<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\HttpProbeOptions;
use Rasuvaeff\DomainMonitor\SitemapService;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\ClientExceptionStub;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeRequest;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeRequestFactory;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeResponse;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Captor;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(SitemapService::class)]
final class SitemapServiceTest
{
    private Captor $requests;

    private Captor $errorMessages;

    private Captor $errorContexts;

    public function countsUrlsInPlainSitemap(): void
    {
        $client = $this->client(new FakeResponse(
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
        $client = $this->client(new FakeResponse(
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
        $client = $this->client(new FakeResponse(statusCode: 200, body: $body));

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
        $client = $this->client(new FakeResponse(statusCode: 200, body: $body));

        $result = (new SitemapService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(sitemapUrl: 'https://example.com/sitemap.xml');

        Assert::same($result->urlCount, 1);
    }

    public function countsZeroUrlsInEmptySitemap(): void
    {
        $client = $this->client(new FakeResponse(statusCode: 200, body: '<urlset></urlset>'));

        $result = (new SitemapService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(sitemapUrl: 'https://example.com/sitemap.xml');

        Assert::same($result->status, CheckStatus::OK);
        Assert::true($result->exists);
        Assert::same($result->urlCount, 0);
    }

    public function returnsWarningForNonOkStatus(): void
    {
        $client = $this->client(new FakeResponse(statusCode: 404, body: 'not found'));

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

        $client = $this->client(new FakeResponse(statusCode: 200, body: '<urlset><url></urlset'));

        $result = (new SitemapService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(sitemapUrl: 'https://example.com/sitemap.xml');

        Assert::same($result->status, CheckStatus::WARNING);
        Assert::true($result->exists);
        Assert::same($result->urlCount, 0);

        Assert::false(\libxml_use_internal_errors(use_errors: $previousState));
    }

    public function returnsUnknownOnNetworkFailure(): void
    {
        $client = $this->failingClient(new ClientExceptionStub(message: 'down'));

        $result = (new SitemapService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(sitemapUrl: 'https://example.com/sitemap.xml');

        Assert::same($result->status, CheckStatus::UNKNOWN);
        Assert::same($result->httpStatus, 0);
        Assert::false($result->exists);
        Assert::same($result->urlCount, 0);
    }

    public function logsErrorWithUrlContextOnNetworkFailure(): void
    {
        $client = $this->failingClient(new ClientExceptionStub(message: 'connection refused'));
        $logger = $this->logger();

        (new SitemapService(httpClient: $client, requestFactory: new FakeRequestFactory(), logger: $logger))
            ->check(sitemapUrl: 'https://example.com/sitemap.xml');

        verify(fn() => $logger->error(Arg::any(), Arg::any()), times: 1);
        Assert::same($this->errorMessages->last(), 'connection refused');
        Assert::same($this->errorContexts->last(), ['url' => 'https://example.com/sitemap.xml']);
    }

    public function appliesOptionsMethodAndHeadersAndDefaultUserAgent(): void
    {
        $client = $this->client(new FakeResponse(statusCode: 200, body: '<urlset/>'));
        $options = new HttpProbeOptions(method: 'HEAD', headers: ['X-Token' => 'secret'], userAgent: 'probe/1.0');

        (new SitemapService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(sitemapUrl: 'https://example.com/sitemap.xml', options: $options);

        $request = $this->requests->last();
        Assert::same($request->getMethod(), 'HEAD');
        Assert::same($request->getHeaderLine(name: 'X-Token'), 'secret');
        Assert::same($request->getHeaderLine(name: 'User-Agent'), 'probe/1.0');
    }

    public function keepsCustomUserAgentHeaderFromOptions(): void
    {
        $client = $this->client(new FakeResponse(statusCode: 200, body: '<urlset/>'));
        $options = new HttpProbeOptions(headers: ['User-Agent' => 'custom-agent'], userAgent: 'default-agent');

        (new SitemapService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(sitemapUrl: 'https://example.com/sitemap.xml', options: $options);

        Assert::same($this->requests->last()->getHeaderLine(name: 'User-Agent'), 'custom-agent');
    }

    private function client(ResponseInterface $response): ClientInterface
    {
        $client = Understudy::for(ClientInterface::class);
        $this->requests = Arg::captor(FakeRequest::class);

        when(fn() => $client->sendRequest($this->requests->capture()))->returns($response);

        return $client;
    }

    private function failingClient(ClientExceptionInterface $exception): ClientInterface
    {
        $client = Understudy::for(ClientInterface::class);

        when(fn() => $client->sendRequest(Arg::any()))->throws($exception);

        return $client;
    }

    private function logger(): LoggerInterface
    {
        $logger = Understudy::for(LoggerInterface::class);
        $this->errorMessages = Arg::captor();
        $this->errorContexts = Arg::captor();

        when(fn() => $logger->error($this->errorMessages->capture(), $this->errorContexts->capture()));

        return $logger;
    }
}
