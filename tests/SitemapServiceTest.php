<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\HttpProbeOptions;
use Rasuvaeff\DomainMonitor\SitemapService;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\ClientExceptionStub;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeRequestFactory;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeResponse;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\RecordingHttpClient;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\RecordingLogger;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(SitemapService::class)]
final class SitemapServiceTest
{
    public function countsUrlsInPlainSitemap(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(
            statusCode: 200,
            body: '<urlset><url/><url/><url/></urlset>',
        ));

        $result = (new SitemapService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(sitemapUrl: 'https://example.com/sitemap.xml');

        Assert::same($result->status, CheckStatus::OK);
        Assert::same($result->httpStatus, 200);
        Assert::true($result->exists);
        Assert::same($result->urlCount, 3);
    }

    public function countsExactlyOneUrlInSitemap(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(
            statusCode: 200,
            body: '<urlset><url/></urlset>',
        ));

        $result = (new SitemapService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(sitemapUrl: 'https://example.com/sitemap.xml');

        Assert::same($result->urlCount, 1);
    }

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

        Assert::same($result->status, CheckStatus::OK);
        Assert::same($result->urlCount, 2);
    }

    public function countsOneUrlInNamespacedSitemap(): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            . '<url><loc>https://example.com/a</loc></url>'
            . '</urlset>';
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 200, body: $body));

        $result = (new SitemapService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(sitemapUrl: 'https://example.com/sitemap.xml');

        Assert::same($result->urlCount, 1);
    }

    public function countsZeroUrlsInEmptySitemap(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 200, body: '<urlset></urlset>'));

        $result = (new SitemapService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(sitemapUrl: 'https://example.com/sitemap.xml');

        Assert::same($result->status, CheckStatus::OK);
        Assert::true($result->exists);
        Assert::same($result->urlCount, 0);
    }

    public function returnsWarningForNonOkStatus(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 404, body: 'not found'));

        $result = (new SitemapService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(sitemapUrl: 'https://example.com/sitemap.xml');

        Assert::same($result->status, CheckStatus::WARNING);
        Assert::same($result->httpStatus, 404);
        Assert::false($result->exists);
        Assert::same($result->urlCount, 0);
    }

    public function returnsWarningForMalformedXml(): void
    {
        $previousState = \libxml_use_internal_errors(use_errors: false);

        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 200, body: '<urlset><url></urlset'));

        $result = (new SitemapService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(sitemapUrl: 'https://example.com/sitemap.xml');

        Assert::same($result->status, CheckStatus::WARNING);
        Assert::true($result->exists);
        Assert::same($result->urlCount, 0);

        Assert::false(\libxml_use_internal_errors(use_errors: $previousState));
    }

    public function returnsUnknownOnNetworkFailure(): void
    {
        $client = new RecordingHttpClient(exception: new ClientExceptionStub(message: 'down'));

        $result = (new SitemapService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(sitemapUrl: 'https://example.com/sitemap.xml');

        Assert::same($result->status, CheckStatus::UNKNOWN);
        Assert::same($result->httpStatus, 0);
        Assert::false($result->exists);
        Assert::same($result->urlCount, 0);
    }

    public function logsErrorWithUrlContextOnNetworkFailure(): void
    {
        $client = new RecordingHttpClient(exception: new ClientExceptionStub(message: 'connection refused'));
        $logger = new RecordingLogger();

        (new SitemapService(httpClient: $client, requestFactory: new FakeRequestFactory(), logger: $logger))
            ->check(sitemapUrl: 'https://example.com/sitemap.xml');

        Assert::count($logger->records, 1);
        Assert::same($logger->records[0]['message'], 'connection refused');
        Assert::same($logger->records[0]['context'], ['url' => 'https://example.com/sitemap.xml']);
    }

    public function appliesOptionsMethodAndHeadersAndDefaultUserAgent(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 200, body: '<urlset/>'));
        $options = new HttpProbeOptions(method: 'HEAD', headers: ['X-Token' => 'secret'], userAgent: 'probe/1.0');

        (new SitemapService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(sitemapUrl: 'https://example.com/sitemap.xml', options: $options);

        Assert::notNull($client->lastRequest);
        Assert::same($client->lastRequest->getMethod(), 'HEAD');
        Assert::same($client->lastRequest->getHeaderLine(name: 'X-Token'), 'secret');
        Assert::same($client->lastRequest->getHeaderLine(name: 'User-Agent'), 'probe/1.0');
    }

    public function keepsCustomUserAgentHeaderFromOptions(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 200, body: '<urlset/>'));
        $options = new HttpProbeOptions(headers: ['User-Agent' => 'custom-agent'], userAgent: 'default-agent');

        (new SitemapService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(sitemapUrl: 'https://example.com/sitemap.xml', options: $options);

        Assert::notNull($client->lastRequest);
        Assert::same($client->lastRequest->getHeaderLine(name: 'User-Agent'), 'custom-agent');
    }
}
