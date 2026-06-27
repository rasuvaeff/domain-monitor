<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\HttpProbeOptions;
use Rasuvaeff\DomainMonitor\RobotsTxtService;
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
#[Covers(RobotsTxtService::class)]
final class RobotsTxtServiceTest
{
    public function extractsMultipleSitemapsCaseInsensitively(): void
    {
        $body = "User-agent: *\nDisallow: /private\n"
            . "Sitemap: https://example.com/sitemap.xml\n"
            . "  sitemap:   https://example.com/news.xml  \n";
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 200, body: $body));

        $result = (new RobotsTxtService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(baseUrl: 'https://example.com');

        Assert::same($result->status, CheckStatus::OK);
        Assert::same($result->httpStatus, 200);
        Assert::true($result->exists);
        Assert::same(
            $result->sitemaps,
            ['https://example.com/sitemap.xml', 'https://example.com/news.xml'],
        );
    }

    public function doesNotMatchSitemapLineWithoutLeadingWhitespaceOrStart(): void
    {
        $body = "xSitemap: https://example.com/fake.xml\nSitemap: https://example.com/real.xml\n";
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 200, body: $body));

        $result = (new RobotsTxtService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(baseUrl: 'https://example.com');

        Assert::same($result->sitemaps, ['https://example.com/real.xml']);
    }

    public function extractsUnicodeSitemapUrl(): void
    {
        $body = "Sitemap: https://example.com/sitemap-ü.xml\n";
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 200, body: $body));

        $result = (new RobotsTxtService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(baseUrl: 'https://example.com');

        Assert::same($result->sitemaps, ['https://example.com/sitemap-ü.xml']);
    }

    public function returnsOkWithoutSitemapsWhenNoneListed(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 200, body: "User-agent: *\nDisallow:\n"));

        $result = (new RobotsTxtService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(baseUrl: 'https://example.com');

        Assert::same($result->status, CheckStatus::OK);
        Assert::same($result->sitemaps, []);
    }

    public function returnsWarningForMissingRobotsTxt(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 404, body: 'Sitemap: https://ignored.example/s.xml'));

        $result = (new RobotsTxtService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(baseUrl: 'https://example.com');

        Assert::same($result->status, CheckStatus::WARNING);
        Assert::same($result->httpStatus, 404);
        Assert::false($result->exists);
        Assert::same($result->sitemaps, []);
    }

    public function returnsUnknownOnNetworkFailure(): void
    {
        $client = new RecordingHttpClient(exception: new ClientExceptionStub(message: 'down'));
        $logger = new RecordingLogger();

        $result = (new RobotsTxtService(httpClient: $client, requestFactory: new FakeRequestFactory(), logger: $logger))
            ->check(baseUrl: 'https://example.com');

        Assert::same($result->status, CheckStatus::UNKNOWN);
        Assert::same($result->httpStatus, 0);
        Assert::false($result->exists);
        Assert::same($result->sitemaps, []);
        Assert::count($logger->records, 1);
        Assert::same($logger->records[0]['message'], 'down');
        Assert::same($logger->records[0]['context'], ['url' => 'https://example.com/robots.txt']);
    }

    public function requestsRobotsTxtAtOriginRootPreservingPort(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 200, body: ''));

        (new RobotsTxtService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(baseUrl: 'https://example.com:8443/deep/path?x=1');

        Assert::instanceOf($client->lastRequest, FakeRequest::class);
        Assert::same($client->lastRequest->getUriString(), 'https://example.com:8443/robots.txt');
    }

    public function appliesOptionsMethodHeadersAndDefaultUserAgent(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 200, body: ''));
        $options = new HttpProbeOptions(method: 'HEAD', headers: ['X-Token' => 'secret'], userAgent: 'probe/1.0');

        (new RobotsTxtService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(baseUrl: 'https://example.com', options: $options);

        Assert::notNull($client->lastRequest);
        Assert::same($client->lastRequest->getMethod(), 'HEAD');
        Assert::same($client->lastRequest->getHeaderLine(name: 'X-Token'), 'secret');
        Assert::same($client->lastRequest->getHeaderLine(name: 'User-Agent'), 'probe/1.0');
    }

    public function keepsCustomUserAgentHeaderFromOptions(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 200, body: ''));
        $options = new HttpProbeOptions(headers: ['User-Agent' => 'custom-agent'], userAgent: 'default-agent');

        (new RobotsTxtService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(baseUrl: 'https://example.com', options: $options);

        Assert::notNull($client->lastRequest);
        Assert::same($client->lastRequest->getHeaderLine(name: 'User-Agent'), 'custom-agent');
    }
}
