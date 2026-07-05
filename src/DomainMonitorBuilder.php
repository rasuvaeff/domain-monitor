<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use Iodev\Whois\Whois;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Fluent builder for {@see DomainMonitor}. HTTP-based checks (probe, content,
 * robots, sitemap, security headers) are wired only after {@see withHttp()}.
 *
 * @api
 */
final class DomainMonitorBuilder
{
    private ?ClientInterface $httpClient = null;
    private ?RequestFactoryInterface $requestFactory = null;
    private ?Whois $whois = null;
    private LoggerInterface $logger;
    private bool $probe = true;
    private bool $ssl = true;
    private bool $dns = true;
    private bool $port = true;
    private bool $securityHeaders = true;
    private bool $robotsTxt = true;
    private bool $sitemap = true;
    private bool $content = true;

    private function __construct()
    {
        $this->logger = new NullLogger();
    }

    public static function create(): self
    {
        return new self();
    }

    public function withHttp(ClientInterface $client, RequestFactoryInterface $requestFactory): self
    {
        $this->httpClient = $client;
        $this->requestFactory = $requestFactory;

        return $this;
    }

    public function withWhois(Whois $whois): self
    {
        $this->whois = $whois;

        return $this;
    }

    public function withLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    public function withoutProbe(): self
    {
        $this->probe = false;

        return $this;
    }

    public function withoutSsl(): self
    {
        $this->ssl = false;

        return $this;
    }

    public function withoutDns(): self
    {
        $this->dns = false;

        return $this;
    }

    public function withoutPort(): self
    {
        $this->port = false;

        return $this;
    }

    public function withoutSecurityHeaders(): self
    {
        $this->securityHeaders = false;

        return $this;
    }

    public function withoutRobotsTxt(): self
    {
        $this->robotsTxt = false;

        return $this;
    }

    public function withoutSitemap(): self
    {
        $this->sitemap = false;

        return $this;
    }

    public function withoutContent(): self
    {
        $this->content = false;

        return $this;
    }

    public function build(): DomainMonitor
    {
        $hasHttp = $this->httpClient !== null && $this->requestFactory !== null;

        $httpProbe = $this->probe && $hasHttp
            ? new HttpProbeService(httpClient: $this->httpClient, requestFactory: $this->requestFactory)
            : null;

        return new DomainMonitor(
            logger: $this->logger,
            httpProbe: $httpProbe,
            ssl: $this->ssl ? new SslCertificateService() : null,
            whois: $this->whois !== null ? new WhoisService(whois: $this->whois) : null,
            dns: $this->dns ? new DnsService() : null,
            port: $this->port ? new PortService() : null,
            securityHeaders: $this->securityHeaders && $httpProbe !== null ? new SecurityHeadersService() : null,
            robotsTxt: $this->robotsTxt && $hasHttp
                ? new RobotsTxtService(httpClient: $this->httpClient, requestFactory: $this->requestFactory)
                : null,
            sitemap: $this->sitemap && $hasHttp
                ? new SitemapService(httpClient: $this->httpClient, requestFactory: $this->requestFactory)
                : null,
            content: $this->content && $hasHttp
                ? new HttpContentCheckService(httpClient: $this->httpClient, requestFactory: $this->requestFactory)
                : null,
        );
    }
}
