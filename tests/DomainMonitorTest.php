<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use InvalidArgumentException;
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
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(DomainMonitor::class)]
final class DomainMonitorTest
{
    public function returnsReportWithAllNullsWhenNoServicesConfigured(): void
    {
        $report = (new DomainMonitor())->check(host: 'example.com');

        Assert::same($report->host, 'example.com');
        Assert::null($report->probe);
        Assert::null($report->ssl);
        Assert::null($report->whois);
        Assert::null($report->dns);
        Assert::null($report->content);
        Assert::null($report->port);
        Assert::null($report->securityHeaders);
        Assert::null($report->robotsTxt);
        Assert::null($report->sitemap);
        Assert::same($report->getStatus(), CheckStatus::UNKNOWN);
    }

    public function normalizesHostBeforeRunningChecks(): void
    {
        $report = (new DomainMonitor())->check(host: 'https://EXAMPLE.com/path?query=1');

        Assert::same($report->host, 'example.com');
    }

    public function throwsWhenSecurityHeadersConfiguredWithoutHttpProbe(): void
    {
        try {
            new DomainMonitor(securityHeaders: new SecurityHeadersService());
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains(
                'SecurityHeadersService requires HttpProbeService to obtain an HTTP response',
            );
        }
    }

    public function probeRunsAndReturnsStatusInReport(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 200));
        $monitor = new DomainMonitor(
            httpProbe: new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory()),
        );

        $report = $monitor->check(host: 'example.com');

        Assert::notNull($report->probe);
        Assert::same($report->probe->status, 200);
        Assert::instanceOf($client->lastRequest, FakeRequest::class);
        Assert::same($client->lastRequest->getUriString(), 'https://example.com/');
    }

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

        Assert::notNull($report->securityHeaders);
        Assert::true($report->securityHeaders->hasHsts);
        Assert::true($report->securityHeaders->hasContentSecurityPolicy);
        Assert::true($report->securityHeaders->hasXFrameOptions);
        Assert::true($report->securityHeaders->hasXContentTypeOptions);
        Assert::same($report->securityHeaders->status, CheckStatus::OK);
    }

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

        Assert::notNull($report->content);
        Assert::true($report->content->requiredTextFound);
        Assert::same($report->content->status, CheckStatus::OK);
    }

    public function contentMakesOwnRequestWhenProbeNotConfigured(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 200, body: 'ok'));
        $monitor = new DomainMonitor(
            content: new HttpContentCheckService(httpClient: $client, requestFactory: new FakeRequestFactory()),
        );

        $report = $monitor->check(host: 'example.com');

        Assert::notNull($report->content);
        Assert::same($report->content->status, CheckStatus::OK);
        Assert::notNull($client->lastRequest);
        Assert::same($client->lastRequest->getUriString(), 'https://example.com/');
    }

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

        Assert::notNull($report->probe);
        Assert::same($report->probe->status, 0);
        Assert::true($report->probe->totalTime >= 0.0);
        Assert::true($report->probe->totalTime < 1.0);
        Assert::same($report->getStatus(), CheckStatus::CRITICAL);
        Assert::null($report->securityHeaders);

        Assert::count($logger->records, 1);
        $probeLog = $logger->records[0];
        Assert::same($probeLog['level'], 'warning');
        Assert::same($probeLog['message'], 'HTTP probe failed');
        Assert::same($probeLog['context']['host'], 'example.com');
        Assert::same($probeLog['context']['check'], 'probe');
        Assert::same($probeLog['context']['error'], 'connection refused');
    }

    public function serviceExceptionIsCaughtAndOmittedFromReport(): void
    {
        $monitor = new DomainMonitor(
            port: new PortService(connector: static fn(): array => throw new \RuntimeException(message: 'port closed')),
        );

        $report = $monitor->check(host: 'example.com');

        Assert::null($report->port);
    }

    public function serviceExceptionIsLoggedWithCheckName(): void
    {
        $logger = new RecordingLogger();
        $monitor = new DomainMonitor(
            logger: $logger,
            port: new PortService(connector: static fn(): array => throw new \RuntimeException(message: 'timeout')),
        );

        $monitor->check(host: 'example.com');

        Assert::count($logger->records, 1);
        Assert::same($logger->records[0]['message'], 'port check failed: timeout');
        Assert::same($logger->records[0]['context']['host'], 'example.com');
        Assert::same($logger->records[0]['context']['check'], 'port');
    }

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

        Assert::same(
            $connectorArgs,
            ['host' => 'example.com', 'port' => 8443, 'timeout' => 15.0],
        );
    }

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

        Assert::same($resolverHost, 'example.com');
        Assert::notNull($report->dns);
        Assert::same($report->dns->a, ['1.2.3.4']);
        Assert::same($report->dns->ns, ['ns1.example.com']);
    }

    public function returnsProperDomainHealthReportInstance(): void
    {
        $monitor = new DomainMonitor();

        $report = $monitor->check(host: 'example.com');

        Assert::instanceOf($report, DomainHealthReport::class);
    }

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

        Assert::same($report->probe?->status, 200);
        Assert::true($report->securityHeaders?->hasHsts);
        Assert::same($report->content?->status, CheckStatus::OK);
        Assert::true($report->robotsTxt?->exists);
        Assert::same($report->robotsTxt?->sitemaps, ['https://example.com/sitemap.xml']);
        Assert::true($report->sitemap?->exists);
        Assert::same($report->sitemap?->urlCount, 1);
        Assert::same($report->dns?->a, ['1.2.3.4']);
        Assert::same($report->port?->status, CheckStatus::OK);
        Assert::same($report->port?->connectTime, 0.02);
        Assert::same($report->getStatus(), CheckStatus::OK);
    }
}
