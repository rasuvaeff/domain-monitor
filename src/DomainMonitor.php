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
use Throwable;

/**
 * @api
 */
final readonly class DomainMonitor implements DomainMonitorInterface
{
    public function __construct(
        private LoggerInterface $logger = new NullLogger(),
        public ?HttpProbeService $httpProbe = null,
        public ?SslCertificateService $ssl = null,
        public ?WhoisService $whois = null,
        public ?DnsService $dns = null,
        public ?PortService $port = null,
        public ?SecurityHeadersService $securityHeaders = null,
        public ?RobotsTxtService $robotsTxt = null,
        public ?SitemapService $sitemap = null,
        public ?HttpContentCheckService $content = null,
    ) {
        if ($securityHeaders !== null && $httpProbe === null) {
            throw new \InvalidArgumentException(
                message: 'SecurityHeadersService requires HttpProbeService to obtain an HTTP response',
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
        );
    }

    #[\Override]
    public function check(string $host, ?DomainMonitorOptions $options = null): DomainHealthReport
    {
        $normalizedHost = (new HostNormalizer())->normalizeHost(hostOrUrl: $host);
        $options ??= new DomainMonitorOptions();
        $baseUrl = "https://{$normalizedHost}";
        $probeOptions = new HttpProbeOptions(
            method: $options->httpMethod,
            timeoutSeconds: $options->timeoutSeconds,
            userAgent: $options->userAgent,
        );

        /** @var list<CheckError> $errors */
        $errors = [];

        $probe = null;
        $response = null;

        $httpProbe = $this->httpProbe;

        if ($httpProbe !== null) {
            $startedAt = \microtime(as_float: true);

            try {
                $probeWithResponse = $httpProbe->probeWithResponse(
                    url: $baseUrl,
                    options: $probeOptions,
                );
                $probe = $probeWithResponse->result;
                $response = $probeWithResponse->response;
            } catch (ClientExceptionInterface $exception) {
                $this->logger->warning(
                    message: 'HTTP probe failed',
                    context: [
                        'host' => $normalizedHost,
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
                host: $normalizedHost,
                callback: fn() => $securityHeadersService->check(response: $response),
                errors: $errors,
            );
        }

        $content = $this->resolveContent(
            host: $normalizedHost,
            baseUrl: $baseUrl,
            response: $response,
            options: $options,
            probeOptions: $probeOptions,
            errors: $errors,
        );

        $ssl = null;
        $sslService = $this->ssl;

        if ($sslService !== null) {
            $ssl = $this->runCheck(
                name: CheckName::Ssl,
                host: $normalizedHost,
                callback: fn() => $sslService->check(
                    host: $normalizedHost,
                    expectedOrg: $options->expectedOrg,
                ),
                errors: $errors,
            );
        }

        $whois = null;
        $whoisService = $this->whois;

        if ($whoisService !== null) {
            $whois = $this->runCheck(
                name: CheckName::Whois,
                host: $normalizedHost,
                callback: fn() => $whoisService->check(host: $normalizedHost),
                errors: $errors,
            );
        }

        $dns = null;
        $dnsService = $this->dns;

        if ($dnsService !== null) {
            $dns = $this->runCheck(
                name: CheckName::Dns,
                host: $normalizedHost,
                callback: fn() => $dnsService->check(host: $normalizedHost),
                errors: $errors,
            );
        }

        $port = null;
        $portService = $this->port;

        if ($portService !== null) {
            $port = $this->runCheck(
                name: CheckName::Port,
                host: $normalizedHost,
                callback: fn() => $portService->check(
                    host: $normalizedHost,
                    port: $options->port,
                    timeoutSeconds: $options->timeoutSeconds,
                ),
                errors: $errors,
            );
        }

        $robotsTxt = null;
        $robotsTxtService = $this->robotsTxt;

        if ($robotsTxtService !== null) {
            $robotsTxt = $this->runCheck(
                name: CheckName::RobotsTxt,
                host: $normalizedHost,
                callback: fn() => $robotsTxtService->check(
                    baseUrl: $baseUrl,
                    options: $probeOptions,
                ),
                errors: $errors,
            );
        }

        $sitemap = null;
        $sitemapService = $this->sitemap;

        if ($sitemapService !== null) {
            $sitemap = $this->runCheck(
                name: CheckName::Sitemap,
                host: $normalizedHost,
                callback: fn() => $sitemapService->check(
                    sitemapUrl: "{$baseUrl}/sitemap.xml",
                    options: $probeOptions,
                ),
                errors: $errors,
            );
        }

        return new DomainHealthReport(
            host: $normalizedHost,
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
    private function runCheck(CheckName $name, string $host, Closure $callback, array &$errors): mixed
    {
        try {
            return $callback();
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
}
