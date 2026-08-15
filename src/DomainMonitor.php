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
use Rasuvaeff\Retry\Retry;
use Rasuvaeff\Retry\RetryExhausted;
use Throwable;

/**
 * @api
 */
final readonly class DomainMonitor implements DomainMonitorInterface
{
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
                thresholds: $options->thresholds,
                errors: [new CheckError(check: CheckName::Probe, message: $exception->getMessage())],
            );
        }
    }

    private function runChecks(string $host, DomainMonitorOptions $options): DomainHealthReport
    {
        $baseUrl = "https://{$host}";
        $probeOptions = new HttpProbeOptions(
            method: $options->httpMethod,
            timeoutSeconds: $options->timeoutSeconds,
            userAgent: $options->userAgent,
        );

        /** @var list<CheckError> $errors */
        $errors = [];
        $retry = $options->retry;

        $probe = null;
        $response = null;

        $httpProbe = $this->httpProbe;

        if ($httpProbe !== null) {
            $startedAt = \microtime(as_float: true);

            try {
                $probeWithResponse = $this->attempt(
                    retry: $retry,
                    operation: static fn(): HttpProbeWithResponse => $httpProbe->probeWithResponse(
                        url: $baseUrl,
                        options: $probeOptions,
                    ),
                );
                $probe = $probeWithResponse->result;
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

                $probe = new ProbeResult(
                    status: 0,
                    totalTime: \microtime(as_float: true) - $startedAt,
                );
            }
        }

        $securityHeaders = null;
        $securityHeadersService = $this->securityHeaders;

        if ($response !== null && $securityHeadersService !== null) {
            $securityHeaders = $this->runCheck(
                name: CheckName::SecurityHeaders,
                host: $host,
                callback: fn() => $securityHeadersService->check(response: $response),
                errors: $errors,
                retry: $retry,
            );
        }

        $content = $this->resolveContent(
            host: $host,
            baseUrl: $baseUrl,
            response: $response,
            options: $options,
            probeOptions: $probeOptions,
            errors: $errors,
            retry: $retry,
        );

        $ssl = null;
        $sslService = $this->ssl;

        if ($sslService !== null) {
            $ssl = $this->runCheck(
                name: CheckName::Ssl,
                host: $host,
                callback: fn() => $sslService->check(
                    host: $host,
                    expectedOrg: $options->expectedOrg,
                ),
                errors: $errors,
                retry: $retry,
            );
        }

        $whois = null;
        $whoisService = $this->whois;

        if ($whoisService !== null) {
            $whois = $this->runCheck(
                name: CheckName::Whois,
                host: $host,
                callback: fn() => $whoisService->check(host: $host),
                errors: $errors,
                retry: $retry,
            );
        }

        $dns = null;
        $dnsService = $this->dns;

        if ($dnsService !== null) {
            $dns = $this->runCheck(
                name: CheckName::Dns,
                host: $host,
                callback: fn() => $dnsService->check(host: $host),
                errors: $errors,
                retry: $retry,
            );
        }

        $port = null;
        $portService = $this->port;

        if ($portService !== null) {
            $port = $this->runCheck(
                name: CheckName::Port,
                host: $host,
                callback: fn() => $portService->check(
                    host: $host,
                    port: $options->port,
                    timeoutSeconds: $options->timeoutSeconds,
                ),
                errors: $errors,
                retry: $retry,
            );
        }

        $robotsTxt = null;
        $robotsTxtService = $this->robotsTxt;

        if ($robotsTxtService !== null) {
            $robotsTxt = $this->runCheck(
                name: CheckName::RobotsTxt,
                host: $host,
                callback: fn() => $robotsTxtService->check(
                    baseUrl: $baseUrl,
                    options: $probeOptions,
                ),
                errors: $errors,
                retry: $retry,
            );
        }

        $sitemap = null;
        $sitemapService = $this->sitemap;

        if ($sitemapService !== null) {
            $sitemap = $this->runCheck(
                name: CheckName::Sitemap,
                host: $host,
                callback: fn() => $sitemapService->check(
                    sitemapUrl: "{$baseUrl}/sitemap.xml",
                    options: $probeOptions,
                ),
                errors: $errors,
                retry: $retry,
            );
        }

        $emailSecurity = null;
        $emailSecurityService = $this->emailSecurity;

        if ($emailSecurityService !== null) {
            $emailSecurity = $this->runCheck(
                name: CheckName::EmailSecurity,
                host: $host,
                callback: fn() => $emailSecurityService->check(host: $host),
                errors: $errors,
                retry: $retry,
            );
        }

        $tlsCipher = null;
        $tlsCipherService = $this->tlsCipher;

        if ($tlsCipherService !== null) {
            $tlsCipher = $this->runCheck(
                name: CheckName::TlsCipher,
                host: $host,
                callback: fn() => $tlsCipherService->check(
                    host: $host,
                    port: $options->port,
                    timeoutSeconds: $options->timeoutSeconds,
                ),
                errors: $errors,
                retry: $retry,
            );
        }

        $cookieSecurity = null;
        $cookieSecurityService = $this->cookieSecurity;

        if ($response !== null && $cookieSecurityService !== null) {
            $cookieSecurity = $this->runCheck(
                name: CheckName::CookieSecurity,
                host: $host,
                callback: fn() => $cookieSecurityService->check(response: $response),
                errors: $errors,
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
            errors: $errors,
            emailSecurity: $emailSecurity,
            tlsCipher: $tlsCipher,
            cookieSecurity: $cookieSecurity,
        );
    }

    /**
     * @param list<CheckError> $errors
     *
     * @param-out list<CheckError> $errors
     */
    private function resolveContent(
        string $host,
        string $baseUrl,
        ?ResponseInterface $response,
        DomainMonitorOptions $options,
        HttpProbeOptions $probeOptions,
        array &$errors,
        ?Retry $retry = null,
    ): ?HttpContentCheck {
        $contentService = $this->content;

        if ($contentService === null) {
            return null;
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
                errors: $errors,
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
            errors: $errors,
            retry: $retry,
        );
    }

    /**
     * @template T
     *
     * @param Closure(): T $callback
     * @param list<CheckError> $errors
     *
     * @param-out list<CheckError> $errors
     *
     * @return T|null
     */
    private function runCheck(CheckName $name, string $host, Closure $callback, array &$errors, ?Retry $retry = null): mixed
    {
        try {
            return $this->attempt(retry: $retry, operation: $callback);
        } catch (Throwable $exception) {
            $this->logger->warning(
                message: \sprintf('%s check failed: %s', $name->value, $exception->getMessage()),
                context: [
                    'host' => $host,
                    'check' => $name->value,
                ],
            );

            $errors[] = new CheckError(check: $name, message: $exception->getMessage());

            return null;
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
