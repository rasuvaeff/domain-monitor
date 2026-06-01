<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\HttpProbeOptions;
use Rasuvaeff\DomainMonitor\SitemapService;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\ClientExceptionStub;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeRequestFactory;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeResponse;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\RecordingHttpClient;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\RecordingLogger;

#[CoversClass(SitemapService::class)]
final class SitemapServiceTest extends TestCase
{
    #[Test]
    public function countsUrlsInPlainSitemap(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(
            statusCode: 200,
            body: '<urlset><url/><url/><url/></urlset>',
        ));

        $result = (new SitemapService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(sitemapUrl: 'https://example.com/sitemap.xml');

        $this->assertSame(CheckStatus::OK, $result->status);
        $this->assertSame(200, $result->httpStatus);
        $this->assertTrue($result->exists);
        $this->assertSame(3, $result->urlCount);
    }

    #[Test]
    public function countsUrlsInNamespacedSitemap(): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            . '<url><loc>https://example.com/a</loc></url>'
            . '<url><loc>https://example.com/b</loc></url>'
            . '</urlset>';
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 200, body: $body));

        $result = (new SitemapService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(sitemapUrl: 'https://example.com/sitemap.xml');

        $this->assertSame(CheckStatus::OK, $result->status);
        $this->assertSame(2, $result->urlCount);
    }

    #[Test]
    public function countsZeroUrlsInEmptySitemap(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 200, body: '<urlset></urlset>'));

        $result = (new SitemapService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(sitemapUrl: 'https://example.com/sitemap.xml');

        $this->assertSame(CheckStatus::OK, $result->status);
        $this->assertTrue($result->exists);
        $this->assertSame(0, $result->urlCount);
    }

    #[Test]
    public function returnsWarningForNonOkStatus(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 404, body: 'not found'));

        $result = (new SitemapService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(sitemapUrl: 'https://example.com/sitemap.xml');

        $this->assertSame(CheckStatus::WARNING, $result->status);
        $this->assertSame(404, $result->httpStatus);
        $this->assertFalse($result->exists);
        $this->assertSame(0, $result->urlCount);
    }

    #[Test]
    public function returnsWarningForMalformedXml(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 200, body: '<urlset><url></urlset'));

        $result = (new SitemapService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(sitemapUrl: 'https://example.com/sitemap.xml');

        $this->assertSame(CheckStatus::WARNING, $result->status);
        $this->assertTrue($result->exists);
        $this->assertSame(0, $result->urlCount);
    }

    #[Test]
    public function returnsUnknownOnNetworkFailure(): void
    {
        $client = new RecordingHttpClient(exception: new ClientExceptionStub(message: 'down'));

        $result = (new SitemapService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(sitemapUrl: 'https://example.com/sitemap.xml');

        $this->assertSame(CheckStatus::UNKNOWN, $result->status);
        $this->assertSame(0, $result->httpStatus);
        $this->assertFalse($result->exists);
        $this->assertSame(0, $result->urlCount);
    }

    #[Test]
    public function logsErrorWithUrlContextOnNetworkFailure(): void
    {
        $client = new RecordingHttpClient(exception: new ClientExceptionStub(message: 'connection refused'));
        $logger = new RecordingLogger();

        (new SitemapService(httpClient: $client, requestFactory: new FakeRequestFactory(), logger: $logger))
            ->check(sitemapUrl: 'https://example.com/sitemap.xml');

        $this->assertCount(1, $logger->records);
        $this->assertSame('connection refused', $logger->records[0]['message']);
        $this->assertSame(['url' => 'https://example.com/sitemap.xml'], $logger->records[0]['context']);
    }

    #[Test]
    public function appliesOptionsMethodAndHeadersAndDefaultUserAgent(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 200, body: '<urlset/>'));
        $options = new HttpProbeOptions(method: 'HEAD', headers: ['X-Token' => 'secret'], userAgent: 'probe/1.0');

        (new SitemapService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(sitemapUrl: 'https://example.com/sitemap.xml', options: $options);

        $this->assertNotNull($client->lastRequest);
        $this->assertSame('HEAD', $client->lastRequest->getMethod());
        $this->assertSame('secret', $client->lastRequest->getHeaderLine(name: 'X-Token'));
        $this->assertSame('probe/1.0', $client->lastRequest->getHeaderLine(name: 'User-Agent'));
    }

    #[Test]
    public function keepsCustomUserAgentHeaderFromOptions(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 200, body: '<urlset/>'));
        $options = new HttpProbeOptions(headers: ['User-Agent' => 'custom-agent'], userAgent: 'default-agent');

        (new SitemapService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(sitemapUrl: 'https://example.com/sitemap.xml', options: $options);

        $this->assertNotNull($client->lastRequest);
        $this->assertSame('custom-agent', $client->lastRequest->getHeaderLine(name: 'User-Agent'));
    }
}
