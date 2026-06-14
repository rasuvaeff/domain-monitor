<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\DnsService;
use Rasuvaeff\DomainMonitor\DomainHealthReport;
use Rasuvaeff\DomainMonitor\DomainMonitor;
use Rasuvaeff\DomainMonitor\DomainMonitorOptions;
use Rasuvaeff\DomainMonitor\HttpContentCheckService;
use Rasuvaeff\DomainMonitor\HttpProbeService;
use Rasuvaeff\DomainMonitor\PortService;
use Rasuvaeff\DomainMonitor\RobotsTxtService;
use Rasuvaeff\DomainMonitor\SecurityHeadersService;
use Rasuvaeff\DomainMonitor\SitemapService;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\ClientExceptionStub;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeRequest;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeRequestFactory;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeResponse;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\RecordingHttpClient;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\RecordingLogger;

#[CoversClass(DomainMonitor::class)]
final class DomainMonitorTest extends TestCase
{
    #[Test]
    public function returnsReportWithAllNullsWhenNoServicesConfigured(): void
    {
        $report = (new DomainMonitor())->check(host: 'example.com');

        $this->assertSame('example.com', $report->host);
        $this->assertNull($report->probe);
        $this->assertNull($report->ssl);
        $this->assertNull($report->whois);
        $this->assertNull($report->dns);
        $this->assertNull($report->content);
        $this->assertNull($report->port);
        $this->assertNull($report->securityHeaders);
        $this->assertNull($report->robotsTxt);
        $this->assertNull($report->sitemap);
        $this->assertSame(CheckStatus::UNKNOWN, $report->getStatus());
    }

    #[Test]
    public function normalizesHostBeforeRunningChecks(): void
    {
        $report = (new DomainMonitor())->check(host: 'https://EXAMPLE.com/path?query=1');

        $this->assertSame('example.com', $report->host);
    }

    #[Test]
    public function throwsWhenSecurityHeadersConfiguredWithoutHttpProbe(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessage(
            message: 'SecurityHeadersService requires HttpProbeService to obtain an HTTP response',
        );

        new DomainMonitor(securityHeaders: new SecurityHeadersService());
    }

    #[Test]
    public function probeRunsAndReturnsStatusInReport(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 200));
        $monitor = new DomainMonitor(
            httpProbe: new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory()),
        );

        $report = $monitor->check(host: 'example.com');

        $this->assertNotNull($report->probe);
        $this->assertSame(200, $report->probe->status);
        $this->assertInstanceOf(FakeRequest::class, $client->lastRequest);
        $this->assertSame('https://example.com/', $client->lastRequest->getUriString());
    }

    #[Test]
    public function reusesProbeResponseForSecurityHeaders(): void
    {
        $response = new FakeResponse(
            statusCode: 200,
            body: '',
            headers: [
                'Strict-Transport-Security' => ['max-age=31536000'],
                'Content-Security-Policy' => ["default-src 'self'"],
                'X-Frame-Options' => ['DENY'],
                'X-Content-Type-Options' => ['nosniff'],
            ],
        );
        $client = new RecordingHttpClient(response: $response);

        $monitor = new DomainMonitor(
            httpProbe: new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory()),
            securityHeaders: new SecurityHeadersService(),
        );

        $report = $monitor->check(host: 'example.com');

        $this->assertNotNull($report->securityHeaders);
        $this->assertTrue($report->securityHeaders->hasHsts);
        $this->assertTrue($report->securityHeaders->hasContentSecurityPolicy);
        $this->assertTrue($report->securityHeaders->hasXFrameOptions);
        $this->assertTrue($report->securityHeaders->hasXContentTypeOptions);
        $this->assertSame(CheckStatus::OK, $report->securityHeaders->status);
    }

    #[Test]
    public function reusesProbeResponseForContentCheck(): void
    {
        $response = new FakeResponse(statusCode: 200, body: 'hello world');
        $client = new RecordingHttpClient(response: $response);
        $probeService = new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory());
        $contentService = new HttpContentCheckService(
            httpClient: new RecordingHttpClient(response: new FakeResponse(statusCode: 500, body: 'wrong')),
            requestFactory: new FakeRequestFactory(),
        );

        $monitor = new DomainMonitor(
            httpProbe: $probeService,
            content: $contentService,
        );

        $report = $monitor->check(
            host: 'example.com',
            options: new DomainMonitorOptions(requiredText: 'hello'),
        );

        $this->assertNotNull($report->content);
        $this->assertTrue($report->content->requiredTextFound);
        $this->assertSame(CheckStatus::OK, $report->content->status);
    }

    #[Test]
    public function contentMakesOwnRequestWhenProbeNotConfigured(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 200, body: 'ok'));
        $monitor = new DomainMonitor(
            content: new HttpContentCheckService(httpClient: $client, requestFactory: new FakeRequestFactory()),
        );

        $report = $monitor->check(host: 'example.com');

        $this->assertNotNull($report->content);
        $this->assertSame(CheckStatus::OK, $report->content->status);
        $this->assertNotNull($client->lastRequest);
        $this->assertSame('https://example.com/', $client->lastRequest->getUriString());
    }

    #[Test]
    public function probeFailureSetsStatusZeroAndOmitsSecurityHeaders(): void
    {
        $client = new RecordingHttpClient(exception: new ClientExceptionStub(message: 'connection refused'));
        $logger = new RecordingLogger();

        $monitor = new DomainMonitor(
            logger: $logger,
            httpProbe: new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory()),
            securityHeaders: new SecurityHeadersService(),
        );

        $report = $monitor->check(host: 'example.com');

        $this->assertNotNull($report->probe);
        $this->assertSame(0, $report->probe->status);
        $this->assertSame(CheckStatus::CRITICAL, $report->getStatus());
        $this->assertNull($report->securityHeaders);

        $probeLog = $logger->records[0];
        $this->assertSame('warning', $probeLog['level']);
        $this->assertSame('HTTP probe failed', $probeLog['message']);
        $this->assertSame('example.com', $probeLog['context']['host']);
        $this->assertSame('probe', $probeLog['context']['check']);
    }

    #[Test]
    public function serviceExceptionIsCaughtAndOmittedFromReport(): void
    {
        $monitor = new DomainMonitor(
            port: new PortService(connector: static fn(): array => throw new \RuntimeException(message: 'port closed')),
        );

        $report = $monitor->check(host: 'example.com');

        $this->assertNull($report->port);
    }

    #[Test]
    public function serviceExceptionIsLoggedWithCheckName(): void
    {
        $logger = new RecordingLogger();
        $monitor = new DomainMonitor(
            logger: $logger,
            port: new PortService(connector: static fn(): array => throw new \RuntimeException(message: 'timeout')),
        );

        $monitor->check(host: 'example.com');

        $this->assertCount(1, $logger->records);
        $this->assertSame('port check failed: timeout', $logger->records[0]['message']);
        $this->assertSame('port', $logger->records[0]['context']['check']);
    }

    #[Test]
    public function passesPortAndTimeoutOptionsToPortService(): void
    {
        $connectorArgs = null;
        $connector = static function (string $host, int $port, float $timeout) use (&$connectorArgs): array {
            $connectorArgs = ['host' => $host, 'port' => $port, 'timeout' => $timeout];

            return ['success' => true, 'connectTime' => 0.01, 'error' => null];
        };

        $monitor = new DomainMonitor(
            port: new PortService(connector: $connector),
        );

        $monitor->check(
            host: 'example.com',
            options: new DomainMonitorOptions(port: 8443, timeoutSeconds: 15.0),
        );

        $this->assertSame(
            ['host' => 'example.com', 'port' => 8443, 'timeout' => 15.0],
            $connectorArgs,
        );
    }

    #[Test]
    public function passesCustomResolverToDnsService(): void
    {
        $resolverHost = null;
        $resolver = static function (string $host, int $type) use (&$resolverHost): array|false {
            $resolverHost = $host;

            return [
                ['type' => 'A', 'ip' => '1.2.3.4'],
                ['type' => 'NS', 'target' => 'ns1.example.com'],
            ];
        };

        $monitor = new DomainMonitor(
            dns: new DnsService(resolver: $resolver),
        );

        $report = $monitor->check(host: 'example.com');

        $this->assertSame('example.com', $resolverHost);
        $this->assertNotNull($report->dns);
        $this->assertSame(['1.2.3.4'], $report->dns->a);
        $this->assertSame(['ns1.example.com'], $report->dns->ns);
    }

    #[Test]
    public function returnsProperDomainHealthReportInstance(): void
    {
        $monitor = new DomainMonitor();

        $report = $monitor->check(host: 'example.com');

        $this->assertInstanceOf(DomainHealthReport::class, $report);
    }

    #[Test]
    public function runsAllControllableServicesAndAssemblesReport(): void
    {
        $probeResponse = new FakeResponse(
            statusCode: 200,
            body: 'healthy content',
            headers: [
                'Strict-Transport-Security' => ['max-age=31536000'],
                'Content-Security-Policy' => ["default-src 'self'"],
                'X-Frame-Options' => ['DENY'],
                'X-Content-Type-Options' => ['nosniff'],
            ],
        );
        $probeClient = new RecordingHttpClient(response: $probeResponse);
        $robotsResponse = new FakeResponse(statusCode: 200, body: "Sitemap: https://example.com/sitemap.xml\n");
        $robotsClient = new RecordingHttpClient(response: $robotsResponse);
        $sitemapResponse = new FakeResponse(statusCode: 200, body: '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.com/</loc></url></urlset>');
        $sitemapClient = new RecordingHttpClient(response: $sitemapResponse);

        $monitor = new DomainMonitor(
            httpProbe: new HttpProbeService(httpClient: $probeClient, requestFactory: new FakeRequestFactory()),
            securityHeaders: new SecurityHeadersService(),
            content: new HttpContentCheckService(httpClient: $probeClient, requestFactory: new FakeRequestFactory()),
            robotsTxt: new RobotsTxtService(httpClient: $robotsClient, requestFactory: new FakeRequestFactory()),
            sitemap: new SitemapService(httpClient: $sitemapClient, requestFactory: new FakeRequestFactory()),
            dns: new DnsService(resolver: static fn(): array|false => [['type' => 'A', 'ip' => '1.2.3.4']]),
            port: new PortService(connector: static fn(): array => ['success' => true, 'connectTime' => 0.02, 'error' => null]),
        );

        $report = $monitor->check(host: 'example.com');

        $this->assertSame(200, $report->probe?->status);
        $this->assertTrue($report->securityHeaders?->hasHsts);
        $this->assertSame(CheckStatus::OK, $report->content?->status);
        $this->assertTrue($report->robotsTxt?->exists);
        $this->assertSame(['https://example.com/sitemap.xml'], $report->robotsTxt?->sitemaps);
        $this->assertTrue($report->sitemap?->exists);
        $this->assertSame(1, $report->sitemap?->urlCount);
        $this->assertSame(['1.2.3.4'], $report->dns?->a);
        $this->assertSame(CheckStatus::OK, $report->port?->status);
        $this->assertSame(0.02, $report->port?->connectTime);
        $this->assertSame(CheckStatus::OK, $report->getStatus());
    }
}
