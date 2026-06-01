<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\HttpProbeOptions;
use Rasuvaeff\DomainMonitor\RobotsTxtService;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\ClientExceptionStub;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeRequest;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeRequestFactory;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeResponse;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\RecordingHttpClient;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\RecordingLogger;

#[CoversClass(RobotsTxtService::class)]
final class RobotsTxtServiceTest extends TestCase
{
    #[Test]
    public function extractsMultipleSitemapsCaseInsensitively(): void
    {
        $body = "User-agent: *\nDisallow: /private\n"
            . "Sitemap: https://example.com/sitemap.xml\n"
            . "  sitemap:   https://example.com/news.xml  \n";
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 200, body: $body));

        $result = (new RobotsTxtService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(baseUrl: 'https://example.com');

        $this->assertSame(CheckStatus::OK, $result->status);
        $this->assertSame(200, $result->httpStatus);
        $this->assertTrue($result->exists);
        $this->assertSame(
            ['https://example.com/sitemap.xml', 'https://example.com/news.xml'],
            $result->sitemaps,
        );
    }

    #[Test]
    public function returnsOkWithoutSitemapsWhenNoneListed(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 200, body: "User-agent: *\nDisallow:\n"));

        $result = (new RobotsTxtService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(baseUrl: 'https://example.com');

        $this->assertSame(CheckStatus::OK, $result->status);
        $this->assertSame([], $result->sitemaps);
    }

    #[Test]
    public function returnsWarningForMissingRobotsTxt(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 404, body: 'Sitemap: https://ignored.example/s.xml'));

        $result = (new RobotsTxtService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(baseUrl: 'https://example.com');

        $this->assertSame(CheckStatus::WARNING, $result->status);
        $this->assertSame(404, $result->httpStatus);
        $this->assertFalse($result->exists);
        $this->assertSame([], $result->sitemaps);
    }

    #[Test]
    public function returnsUnknownOnNetworkFailure(): void
    {
        $client = new RecordingHttpClient(exception: new ClientExceptionStub(message: 'down'));
        $logger = new RecordingLogger();

        $result = (new RobotsTxtService(httpClient: $client, requestFactory: new FakeRequestFactory(), logger: $logger))
            ->check(baseUrl: 'https://example.com');

        $this->assertSame(CheckStatus::UNKNOWN, $result->status);
        $this->assertSame(0, $result->httpStatus);
        $this->assertFalse($result->exists);
        $this->assertSame([], $result->sitemaps);
        $this->assertCount(1, $logger->records);
        $this->assertSame('down', $logger->records[0]['message']);
        $this->assertSame(['url' => 'https://example.com/robots.txt'], $logger->records[0]['context']);
    }

    #[Test]
    public function requestsRobotsTxtAtOriginRootPreservingPort(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 200, body: ''));

        (new RobotsTxtService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(baseUrl: 'https://example.com:8443/deep/path?x=1');

        $this->assertInstanceOf(FakeRequest::class, $client->lastRequest);
        $this->assertSame('https://example.com:8443/robots.txt', $client->lastRequest->getUriString());
    }

    #[Test]
    public function appliesOptionsMethodHeadersAndDefaultUserAgent(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 200, body: ''));
        $options = new HttpProbeOptions(method: 'HEAD', headers: ['X-Token' => 'secret'], userAgent: 'probe/1.0');

        (new RobotsTxtService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(baseUrl: 'https://example.com', options: $options);

        $this->assertNotNull($client->lastRequest);
        $this->assertSame('HEAD', $client->lastRequest->getMethod());
        $this->assertSame('secret', $client->lastRequest->getHeaderLine(name: 'X-Token'));
        $this->assertSame('probe/1.0', $client->lastRequest->getHeaderLine(name: 'User-Agent'));
    }

    #[Test]
    public function keepsCustomUserAgentHeaderFromOptions(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 200, body: ''));
        $options = new HttpProbeOptions(headers: ['User-Agent' => 'custom-agent'], userAgent: 'default-agent');

        (new RobotsTxtService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(baseUrl: 'https://example.com', options: $options);

        $this->assertNotNull($client->lastRequest);
        $this->assertSame('custom-agent', $client->lastRequest->getHeaderLine(name: 'User-Agent'));
    }
}
