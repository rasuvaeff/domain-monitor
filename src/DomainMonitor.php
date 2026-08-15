<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use Closure;
use Iodev\Whois\Whois;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Rasuvaeff\CircuitBreaker\CircuitOpenException;
use Rasuvaeff\Result\Result;
use Rasuvaeff\Retry\Retry;
use Rasuvaeff\Retry\RetryExhausted;
use Throwable;

/**
 * @api
 */
final readonly class DomainMonitor implements DomainMonitorInterface
{
    private const string BUDGET_MESSAGE = 'Time budget exceeded';

    public function __construct(
        private LoggerInterface $logger = new NullLogger(),
        public ?HttpProbeServiceInterface $httpProbe = null,
        public ?SslCertificateServiceInterface $ssl = null,
        public ?WhoisServiceInterface $whois = null,
        public ?DnsServiceInterface $dns = null,
        public ?PortServiceInterface $port = null,
        public ?SecurityHeadersServiceInterface $securityHeaders = null,
        public ?RobotsTxtServiceInterface $robotsTxt = null,
        public ?SitemapServiceInterface $sitemap = null,
        public ?HttpContentCheckServiceInterface $content = null,
        public ?EmailSecurityServiceInterface $emailSecurity = null,
        public ?TlsCipherServiceInterface $tlsCipher = null,
        public ?CookieSecurityServiceInterface $cookieSecurity = null,
    ) {
        if (($securityHeaders !== null || $cookieSecurity !== null) && $httpProbe === null) {
            throw new \InvalidArgumentException(
                message: 'SecurityHeadersService and CookieSecurityService require HttpProbeService to obtain an HTTP response',
            );
        }
    }

    /**
     * Wire every check from a single PSR-18 client + PSR-17 factory (+ optional WHOIS).
     */
    public static function create(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        ?Whois $whois = null,
        LoggerInterface $logger = new NullLogger(),
    ): self {
        return new self(
            logger: $logger,
            httpProbe: new HttpProbeService(httpClient: $httpClient, requestFactory: $requestFactory),
            ssl: new SslCertificateService(),
            whois: $whois !== null ? new WhoisService(whois: $whois) : null,
            dns: new DnsService(),
            port: new PortService(),
            securityHeaders: new SecurityHeadersService(),
            robotsTxt: new RobotsTxtService(httpClient: $httpClient, requestFactory: $requestFactory),
            sitemap: new SitemapService(httpClient: $httpClient, requestFactory: $requestFactory),
            content: new HttpContentCheckService(httpClient: $httpClient, requestFactory: $requestFactory),
            emailSecurity: new EmailSecurityService(),
            tlsCipher: new TlsCipherService(),
            cookieSecurity: new CookieSecurityService(),
        );
    }

    #[\Override]
    public function check(string $host, ?DomainMonitorOptions $options = null): DomainHealthReport
    {
        $normalizedHost = (new HostNormalizer())->normalizeHost(hostOrUrl: $host);
        $options ??= new DomainMonitorOptions();

        $breaker = $options->circuitBreaker;

        if ($breaker === null) {
            return $this->runChecks(host: $normalizedHost, options: $options);
        }

        try {
            return $breaker->call(
                callback: fn(): DomainHealthReport => $this->runChecks(host: $normalizedHost, options: $options),
            );
        } catch (CircuitOpenException $exception) {
            $this->logger->warning(
                message: 'Domain check rejected by circuit breaker',
                context: [
                    'host' => $normalizedHost,
                    'error' => $exception->getMessage(),
                ],
            );

            return new DomainHealthReport(
                host: $normalizedHost,
                probe: Result::err(error: new CheckError(check: CheckName::Probe, message: $exception->getMessage())),
                thresholds: $options->thresholds,
            );
        }
    }

    #[\Override]
    public function checkMany(array $hosts, ?DomainMonitorOptions $options = null): array
    {
        $reports = [];

        foreach ($hosts as $host) {
            $report = $this->check(host: $host, options: $options);
            $reports[$report->host] = $report;
        }

        return $reports;
    }

    private function runChecks(string $host, DomainMonitorOptions $options): DomainHealthReport
    {
        $baseUrl = "https://{$host}";
        $probeOptions = new HttpProbeOptions(
            method: $options->httpMethod,
            timeoutSeconds: $options->timeoutSeconds,
            userAgent: $options->userAgent,
        );

        $retry = $options->retry;
        $deadline = $options->maxDuration !== null
            ? \microtime(as_float: true) + $options->maxDuration->toSeconds()
            : null;

        /** @var Result<ProbeResult, CheckError>|null $probe */
        $probe = null;
        $response = null;

        $httpProbe = $this->httpProbe;

        if ($httpProbe !== null && $this->deadlineHit(deadline: $deadline)) {
            $probe = Result::err(error: new CheckError(check: CheckName::Probe, message: self::BUDGET_MESSAGE));
        } elseif ($httpProbe !== null) {
            $startedAt = \microtime(as_float: true);

            try {
                $probeWithResponse = $this->attempt(
                    retry: $retry,
                    operation: static fn(): HttpProbeWithResponse => $httpProbe->probeWithResponse(
                        url: $baseUrl,
                        options: $probeOptions,
                    ),
                );
                $probe = Result::ok(value: $probeWithResponse->result);
                $response = $probeWithResponse->response;
            } catch (ClientExceptionInterface|RetryExhausted $exception) {
                $this->logger->warning(
                    message: 'HTTP probe failed',
                    context: [
                        'host' => $host,
                        'check' => 'probe',
                        'error' => $exception->getMessage(),
                    ],
                );

                $probe = Result::ok(value: new ProbeResult(
                    status: 0,
                    totalTime: \microtime(as_float: true) - $startedAt,
                ));
            }
        }

        /** @var Result<SecurityHeadersCheck, CheckError>|null $securityHeaders */
        $securityHeaders = null;
        $securityHeadersService = $this->securityHeaders;

        if ($response !== null && $securityHeadersService !== null) {
            $securityHeaders = $this->runBudgeted(
                name: CheckName::SecurityHeaders,
                host: $host,
                deadline: $deadline,
                callback: fn() => $securityHeadersService->check(response: $response),
                retry: $retry,
            );
        }

        $content = $this->resolveContent(
            host: $host,
            baseUrl: $baseUrl,
            response: $response,
            options: $options,
            probeOptions: $probeOptions,
            retry: $retry,
            deadline: $deadline,
        );

        /** @var Result<SslCertificate, CheckError>|null $ssl */
        $ssl = null;
        $sslService = $this->ssl;

        if ($sslService !== null) {
            $ssl = $this->runBudgeted(
                name: CheckName::Ssl,
                host: $host,
                deadline: $deadline,
                callback: fn() => $sslService->check(
                    host: $host,
                    expectedOrg: $options->expectedOrg,
                ),
                retry: $retry,
            );
        }

        /** @var Result<TldInfo, CheckError>|null $whois */
        $whois = null;
        $whoisService = $this->whois;

        if ($whoisService !== null) {
            $whois = $this->runBudgeted(
                name: CheckName::Whois,
                host: $host,
                deadline: $deadline,
                callback: fn() => $whoisService->check(host: $host),
                retry: $retry,
            );
        }

        /** @var Result<DnsRecords, CheckError>|null $dns */
        $dns = null;
        $dnsService = $this->dns;

        if ($dnsService !== null) {
            $dns = $this->runBudgeted(
                name: CheckName::Dns,
                host: $host,
                deadline: $deadline,
                callback: fn() => $dnsService->check(host: $host),
                retry: $retry,
            );
        }

        /** @var Result<PortCheck, CheckError>|null $port */
        $port = null;
        $portService = $this->port;

        if ($portService !== null) {
            $port = $this->runBudgeted(
                name: CheckName::Port,
                host: $host,
                deadline: $deadline,
                callback: fn() => $portService->check(
                    host: $host,
                    port: $options->port,
                    timeoutSeconds: $options->timeoutSeconds,
                ),
                retry: $retry,
            );
        }

        /** @var Result<RobotsTxtCheck, CheckError>|null $robotsTxt */
        $robotsTxt = null;
        $robotsTxtService = $this->robotsTxt;

        if ($robotsTxtService !== null) {
            $robotsTxt = $this->runBudgeted(
                name: CheckName::RobotsTxt,
                host: $host,
                deadline: $deadline,
                callback: fn() => $robotsTxtService->check(
                    baseUrl: $baseUrl,
                    options: $probeOptions,
                ),
                retry: $retry,
            );
        }

        /** @var Result<SitemapCheck, CheckError>|null $sitemap */
        $sitemap = null;
        $sitemapService = $this->sitemap;

        if ($sitemapService !== null) {
            $sitemap = $this->runBudgeted(
                name: CheckName::Sitemap,
                host: $host,
                deadline: $deadline,
                callback: fn() => $sitemapService->check(
                    sitemapUrl: "{$baseUrl}/sitemap.xml",
                    options: $probeOptions,
                ),
                retry: $retry,
            );
        }

        /** @var Result<EmailSecurityCheck, CheckError>|null $emailSecurity */
        $emailSecurity = null;
        $emailSecurityService = $this->emailSecurity;

        if ($emailSecurityService !== null) {
            $emailSecurity = $this->runBudgeted(
                name: CheckName::EmailSecurity,
                host: $host,
                deadline: $deadline,
                callback: fn() => $emailSecurityService->check(host: $host),
                retry: $retry,
            );
        }

        /** @var Result<TlsCipherCheck, CheckError>|null $tlsCipher */
        $tlsCipher = null;
        $tlsCipherService = $this->tlsCipher;

        if ($tlsCipherService !== null) {
            $tlsCipher = $this->runBudgeted(
                name: CheckName::TlsCipher,
                host: $host,
                deadline: $deadline,
                callback: fn() => $tlsCipherService->check(
                    host: $host,
                    port: $options->port,
                    timeoutSeconds: $options->timeoutSeconds,
                ),
                retry: $retry,
            );
        }

        /** @var Result<CookieSecurityCheck, CheckError>|null $cookieSecurity */
        $cookieSecurity = null;
        $cookieSecurityService = $this->cookieSecurity;

        if ($response !== null && $cookieSecurityService !== null) {
            $cookieSecurity = $this->runBudgeted(
                name: CheckName::CookieSecurity,
                host: $host,
                deadline: $deadline,
                callback: fn() => $cookieSecurityService->check(response: $response),
                retry: $retry,
            );
        }

        return new DomainHealthReport(
            host: $host,
            probe: $probe,
            ssl: $ssl,
            whois: $whois,
            dns: $dns,
            content: $content,
            port: $port,
            securityHeaders: $securityHeaders,
            robotsTxt: $robotsTxt,
            sitemap: $sitemap,
            thresholds: $options->thresholds,
            emailSecurity: $emailSecurity,
            tlsCipher: $tlsCipher,
            cookieSecurity: $cookieSecurity,
        );
    }

    /**
     * @return Result<HttpContentCheck, CheckError>|null
     */
    private function resolveContent(
        string $host,
        string $baseUrl,
        ?ResponseInterface $response,
        DomainMonitorOptions $options,
        HttpProbeOptions $probeOptions,
        ?Retry $retry = null,
        ?float $deadline = null,
    ): ?Result {
        $contentService = $this->content;

        if ($contentService === null) {
            return null;
        }

        if ($this->deadlineHit(deadline: $deadline)) {
            return Result::err(error: new CheckError(check: CheckName::Content, message: self::BUDGET_MESSAGE));
        }

        if ($response !== null) {
            return $this->runCheck(
                name: CheckName::Content,
                host: $host,
                callback: fn() => $contentService->checkFromResponse(
                    response: $response,
                    expectedStatus: $options->expectedStatus,
                    requiredText: $options->requiredText,
                    forbiddenText: $options->forbiddenText,
                ),
                retry: $retry,
            );
        }

        return $this->runCheck(
            name: CheckName::Content,
            host: $host,
            callback: fn() => $contentService->check(
                url: $baseUrl,
                expectedStatus: $options->expectedStatus,
                requiredText: $options->requiredText,
                forbiddenText: $options->forbiddenText,
                options: $probeOptions,
            ),
            retry: $retry,
        );
    }

    /**
     * @template T of object
     *
     * @param Closure(): ?T $callback
     *
     * @return Result<T, CheckError>
     */
    private function runBudgeted(CheckName $name, string $host, ?float $deadline, Closure $callback, ?Retry $retry = null): Result
    {
        if ($this->deadlineHit(deadline: $deadline)) {
            return Result::err(error: new CheckError(check: $name, message: self::BUDGET_MESSAGE));
        }

        return $this->runCheck(name: $name, host: $host, callback: $callback, retry: $retry);
    }

    private function deadlineHit(?float $deadline): bool
    {
        return $deadline !== null && \microtime(as_float: true) >= $deadline;
    }

    /**
     * @template T of object
     *
     * @param Closure(): ?T $callback
     *
     * @return Result<T, CheckError>
     */
    private function runCheck(CheckName $name, string $host, Closure $callback, ?Retry $retry = null): Result
    {
        try {
            $result = $this->attempt(retry: $retry, operation: $callback);

            if ($result === null) {
                throw new \UnexpectedValueException(message: 'Service returned no result');
            }

            return Result::ok(value: $result);
        } catch (Throwable $exception) {
            $this->logger->warning(
                message: \sprintf('%s check failed: %s', $name->value, $exception->getMessage()),
                context: [
                    'host' => $host,
                    'check' => $name->value,
                ],
            );

            return Result::err(error: new CheckError(check: $name, message: $exception->getMessage()));
        }
    }

    /**
     * @template T
     *
     * @param Closure(): T $operation
     *
     * @return T
     */
    private function attempt(?Retry $retry, Closure $operation): mixed
    {
        return $retry !== null ? $retry->run($operation) : $operation();
    }
}
