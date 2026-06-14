<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use Closure;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * @api
 */
final readonly class DomainMonitor
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
                name: 'security-headers',
                host: $normalizedHost,
                callback: fn() => $securityHeadersService->check(response: $response),
            );
        }

        $content = $this->resolveContent(
            host: $normalizedHost,
            baseUrl: $baseUrl,
            response: $response,
            options: $options,
            probeOptions: $probeOptions,
        );

        $ssl = null;
        $sslService = $this->ssl;

        if ($sslService !== null) {
            $ssl = $this->runCheck(
                name: 'ssl',
                host: $normalizedHost,
                callback: fn() => $sslService->check(
                    host: $normalizedHost,
                    expectedOrg: $options->expectedOrg,
                ),
            );
        }

        $whois = null;
        $whoisService = $this->whois;

        if ($whoisService !== null) {
            $whois = $this->runCheck(
                name: 'whois',
                host: $normalizedHost,
                callback: fn() => $whoisService->check(host: $normalizedHost),
            );
        }

        $dns = null;
        $dnsService = $this->dns;

        if ($dnsService !== null) {
            $dns = $this->runCheck(
                name: 'dns',
                host: $normalizedHost,
                callback: fn() => $dnsService->check(host: $normalizedHost),
            );
        }

        $port = null;
        $portService = $this->port;

        if ($portService !== null) {
            $port = $this->runCheck(
                name: 'port',
                host: $normalizedHost,
                callback: fn() => $portService->check(
                    host: $normalizedHost,
                    port: $options->port,
                    timeoutSeconds: $options->timeoutSeconds,
                ),
            );
        }

        $robotsTxt = null;
        $robotsTxtService = $this->robotsTxt;

        if ($robotsTxtService !== null) {
            $robotsTxt = $this->runCheck(
                name: 'robots-txt',
                host: $normalizedHost,
                callback: fn() => $robotsTxtService->check(
                    baseUrl: $baseUrl,
                    options: $probeOptions,
                ),
            );
        }

        $sitemap = null;
        $sitemapService = $this->sitemap;

        if ($sitemapService !== null) {
            $sitemap = $this->runCheck(
                name: 'sitemap',
                host: $normalizedHost,
                callback: fn() => $sitemapService->check(
                    sitemapUrl: "{$baseUrl}/sitemap.xml",
                    options: $probeOptions,
                ),
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
        );
    }

    private function resolveContent(
        string $host,
        string $baseUrl,
        ?ResponseInterface $response,
        DomainMonitorOptions $options,
        HttpProbeOptions $probeOptions,
    ): ?HttpContentCheck {
        $contentService = $this->content;

        if ($contentService === null) {
            return null;
        }

        if ($response !== null) {
            return $this->runCheck(
                name: 'content',
                host: $host,
                callback: fn() => $contentService->checkFromResponse(
                    response: $response,
                    expectedStatus: $options->expectedStatus,
                    requiredText: $options->requiredText,
                    forbiddenText: $options->forbiddenText,
                ),
            );
        }

        return $this->runCheck(
            name: 'content',
            host: $host,
            callback: fn() => $contentService->check(
                url: $baseUrl,
                expectedStatus: $options->expectedStatus,
                requiredText: $options->requiredText,
                forbiddenText: $options->forbiddenText,
                options: $probeOptions,
            ),
        );
    }

    /**
     * @template T
     *
     * @param Closure(): T $callback
     *
     * @return T|null
     */
    private function runCheck(string $name, string $host, Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            $this->logger->warning(
                message: \sprintf('%s check failed: %s', $name, $exception->getMessage()),
                context: [
                    'host' => $host,
                    'check' => $name,
                ],
            );

            return null;
        }
    }
}
