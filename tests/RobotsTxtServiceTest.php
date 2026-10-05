<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\HttpProbeOptions;
use Rasuvaeff\DomainMonitor\RobotsTxtService;
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
#[Covers(RobotsTxtService::class)]
final class RobotsTxtServiceTest
{
    private Captor $requests;

    private Captor $errorMessages;

    private Captor $errorContexts;

    public function extractsMultipleSitemapsCaseInsensitively(): void
    {
        $body = "User-agent: *\nDisallow: /private\n"
            . "Sitemap: https://example.com/sitemap.xml\n"
            . "  sitemap:   https://example.com/news.xml  \n";
        $client = $this->client(new FakeResponse(statusCode: 200, body: $body));

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
        $client = $this->client(new FakeResponse(statusCode: 200, body: $body));

        $result = (new RobotsTxtService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(baseUrl: 'https://example.com');

        Assert::same($result->sitemaps, ['https://example.com/real.xml']);
    }

    public function extractsUnicodeSitemapUrl(): void
    {
        $body = "Sitemap: https://example.com/sitemap-ü.xml\n";
        $client = $this->client(new FakeResponse(statusCode: 200, body: $body));

        $result = (new RobotsTxtService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(baseUrl: 'https://example.com');

        Assert::same($result->sitemaps, ['https://example.com/sitemap-ü.xml']);
    }

    public function returnsOkWithoutSitemapsWhenNoneListed(): void
    {
        $client = $this->client(new FakeResponse(statusCode: 200, body: "User-agent: *\nDisallow:\n"));

        $result = (new RobotsTxtService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(baseUrl: 'https://example.com');

        Assert::same($result->status, CheckStatus::OK);
        Assert::same($result->sitemaps, []);
    }

    public function returnsWarningForMissingRobotsTxt(): void
    {
        $client = $this->client(new FakeResponse(statusCode: 404, body: 'Sitemap: https://ignored.example/s.xml'));

        $result = (new RobotsTxtService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(baseUrl: 'https://example.com');

        Assert::same($result->status, CheckStatus::WARNING);
        Assert::same($result->httpStatus, 404);
        Assert::false($result->exists);
        Assert::same($result->sitemaps, []);
    }

    public function returnsUnknownOnNetworkFailure(): void
    {
        $client = $this->failingClient(new ClientExceptionStub(message: 'down'));
        $logger = $this->logger();

        $result = (new RobotsTxtService(httpClient: $client, requestFactory: new FakeRequestFactory(), logger: $logger))
            ->check(baseUrl: 'https://example.com');

        Assert::same($result->status, CheckStatus::UNKNOWN);
        Assert::same($result->httpStatus, 0);
        Assert::false($result->exists);
        Assert::same($result->sitemaps, []);
        verify(fn() => $logger->error(Arg::any(), Arg::any()), times: 1);
        Assert::same($this->errorMessages->last(), 'down');
        Assert::same($this->errorContexts->last(), ['url' => 'https://example.com/robots.txt']);
    }

    public function requestsRobotsTxtAtOriginRootPreservingPort(): void
    {
        $client = $this->client(new FakeResponse(statusCode: 200, body: ''));

        (new RobotsTxtService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(baseUrl: 'https://example.com:8443/deep/path?x=1');

        Assert::same($this->requests->last()->getUriString(), 'https://example.com:8443/robots.txt');
    }

    public function appliesOptionsMethodHeadersAndDefaultUserAgent(): void
    {
        $client = $this->client(new FakeResponse(statusCode: 200, body: ''));
        $options = new HttpProbeOptions(method: 'HEAD', headers: ['X-Token' => 'secret'], userAgent: 'probe/1.0');

        (new RobotsTxtService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(baseUrl: 'https://example.com', options: $options);

        $request = $this->requests->last();
        Assert::same($request->getMethod(), 'HEAD');
        Assert::same($request->getHeaderLine(name: 'X-Token'), 'secret');
        Assert::same($request->getHeaderLine(name: 'User-Agent'), 'probe/1.0');
    }

    public function keepsCustomUserAgentHeaderFromOptions(): void
    {
        $client = $this->client(new FakeResponse(statusCode: 200, body: ''));
        $options = new HttpProbeOptions(headers: ['User-Agent' => 'custom-agent'], userAgent: 'default-agent');

        (new RobotsTxtService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(baseUrl: 'https://example.com', options: $options);

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
