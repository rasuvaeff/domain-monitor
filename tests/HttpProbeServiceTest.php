<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Rasuvaeff\DomainMonitor\HttpProbeOptions;
use Rasuvaeff\DomainMonitor\HttpProbeService;
use Rasuvaeff\DomainMonitor\HttpProbeWithResponse;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\ClientExceptionStub;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeRequest;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeRequestFactory;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeResponse;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Captor;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(HttpProbeService::class)]
final class HttpProbeServiceTest
{
    private Captor $requests;

    private Captor $errorMessages;

    private Captor $errorContexts;

    public function returnsStatusFromResponse(): void
    {
        $client = $this->client(new FakeResponse(statusCode: 204));

        $result = (new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(url: 'https://example.com');

        Assert::same($result->status, 204);
        Assert::true($result->totalTime >= 0.0);
        Assert::true($result->totalTime < 10.0);
    }

    public function appliesMethodHeadersAndDefaultUserAgent(): void
    {
        $client = $this->client(new FakeResponse(statusCode: 200));

        (new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(url: 'https://example.com', options: new HttpProbeOptions(method: 'head', headers: ['X-Test' => '1']));

        $request = $this->requests->last();
        Assert::same($request->getMethod(), 'HEAD');
        Assert::same($request->getUriString(), 'https://example.com/');
        Assert::same($request->getHeaderLine(name: 'X-Test'), '1');
        Assert::same($request->getHeaderLine(name: 'User-Agent'), 'rasuvaeff/domain-monitor');
    }

    public function keepsCustomUserAgentHeaderFromOptions(): void
    {
        $client = $this->client(new FakeResponse(statusCode: 200));

        (new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(url: 'https://example.com', options: new HttpProbeOptions(headers: ['User-Agent' => 'custom-agent']));

        Assert::same($this->requests->last()->getHeaderLine(name: 'User-Agent'), 'custom-agent');
    }

    public function returnsStatusZeroAndLogsOnNetworkFailure(): void
    {
        $client = $this->failingClient(new ClientExceptionStub(message: 'down'));
        $logger = $this->logger();

        $result = (new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory(), logger: $logger))
            ->check(url: 'https://example.com');

        Assert::same($result->status, 0);
        Assert::true($result->totalTime >= 0.0);
        Assert::true($result->totalTime < 10.0);
        verify(fn() => $logger->error(Arg::any(), Arg::any()), times: 1);
        Assert::same($this->errorMessages->last(), 'down');
        Assert::same($this->errorContexts->last(), ['url' => 'https://example.com/']);
    }

    public function probeWithResponseReturnsResultAndResponse(): void
    {
        $response = new FakeResponse(statusCode: 200);
        $client = $this->client($response);

        $result = (new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->probeWithResponse(url: 'https://example.com');

        Assert::instanceOf($result, HttpProbeWithResponse::class);
        Assert::same($result->result->status, 200);
        Assert::same($result->response, $response);
    }

    public function probeWithResponseAppliesOptionsAndMeasuresTime(): void
    {
        $client = $this->client(new FakeResponse(statusCode: 204));

        $result = (new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->probeWithResponse(
                url: 'https://example.com',
                options: new HttpProbeOptions(method: 'head', headers: ['X-Test' => '1']),
            );

        Assert::same($result->result->status, 204);
        Assert::true($result->result->totalTime >= 0.0);
        Assert::true($result->result->totalTime < 10.0);
        $request = $this->requests->last();
        Assert::same($request->getMethod(), 'HEAD');
        Assert::same($request->getHeaderLine(name: 'X-Test'), '1');
    }

    public function probeWithResponseThrowsOnNetworkFailure(): void
    {
        $client = Understudy::strict($this->failingClient(new ClientExceptionStub(message: 'timeout')));

        Expect::exception(ClientExceptionStub::class);

        (new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->probeWithResponse(url: 'https://example.com');
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
