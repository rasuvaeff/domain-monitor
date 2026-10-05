<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use InvalidArgumentException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\HttpContentCheckService;
use Rasuvaeff\DomainMonitor\HttpProbeOptions;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\ClientExceptionStub;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeRequest;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeRequestFactory;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeResponse;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Captor;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(HttpContentCheckService::class)]
final class HttpContentCheckServiceTest
{
    private Captor $requests;

    private Captor $errorMessages;

    private Captor $errorContexts;

    public function returnsOkWhenStatusMatchesAndNoTextConstraints(): void
    {
        $service = $this->service(new FakeResponse(statusCode: 200, body: 'anything'));

        $result = $service->check(url: 'https://example.com');

        Assert::same($result->status, CheckStatus::OK);
        Assert::same($result->httpStatus, 200);
        Assert::null($result->finalUrl);
        Assert::true($result->requiredTextFound);
        Assert::false($result->forbiddenTextFound);
    }

    public function returnsOkWhenRequiredTextFoundAndForbiddenAbsent(): void
    {
        $service = $this->service(new FakeResponse(statusCode: 200, body: 'hello world'));

        $result = $service->check(url: 'https://example.com', requiredText: 'hello', forbiddenText: 'error');

        Assert::same($result->status, CheckStatus::OK);
        Assert::true($result->requiredTextFound);
        Assert::false($result->forbiddenTextFound);
    }

    public function returnsCriticalWhenRequiredTextMissing(): void
    {
        $service = $this->service(new FakeResponse(statusCode: 200, body: 'goodbye'));

        $result = $service->check(url: 'https://example.com', requiredText: 'hello');

        Assert::same($result->status, CheckStatus::CRITICAL);
        Assert::false($result->requiredTextFound);
    }

    public function returnsCriticalWhenForbiddenTextFound(): void
    {
        $service = $this->service(new FakeResponse(statusCode: 200, body: 'blocked keyword here'));

        $result = $service->check(url: 'https://example.com', forbiddenText: 'keyword');

        Assert::same($result->status, CheckStatus::CRITICAL);
        Assert::true($result->forbiddenTextFound);
    }

    public function returnsCriticalWhenStatusDiffersFromExpected(): void
    {
        $service = $this->service(new FakeResponse(statusCode: 500, body: 'hello'));

        $result = $service->check(url: 'https://example.com', requiredText: 'hello');

        Assert::same($result->status, CheckStatus::CRITICAL);
        Assert::same($result->httpStatus, 500);
        Assert::true($result->requiredTextFound);
    }

    public function honorsCustomExpectedStatus(): void
    {
        $service = $this->service(new FakeResponse(statusCode: 301, body: ''));

        $result = $service->check(url: 'https://example.com', expectedStatus: 301);

        Assert::same($result->status, CheckStatus::OK);
        Assert::same($result->httpStatus, 301);
    }

    #[DataProvider('invalidExpectedStatusProvider')]
    public function throwsOnInvalidExpectedStatus(int $expectedStatus): void
    {
        try {
            $this->service(new FakeResponse())->check(url: 'https://example.com', expectedStatus: $expectedStatus);
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains(\sprintf('Invalid HTTP status %d', $expectedStatus));
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidExpectedStatusProvider(): iterable
    {
        yield 'below range' => [99];
        yield 'above range' => [600];
    }

    public function acceptsBoundaryExpectedStatus100(): void
    {
        $service = $this->service(new FakeResponse(statusCode: 100, body: ''));

        $result = $service->check(url: 'https://example.com', expectedStatus: 100);

        Assert::same($result->status, CheckStatus::OK);
        Assert::same($result->httpStatus, 100);
    }

    public function acceptsBoundaryExpectedStatus599(): void
    {
        $service = $this->service(new FakeResponse(statusCode: 599, body: ''));

        $result = $service->check(url: 'https://example.com', expectedStatus: 599);

        Assert::same($result->status, CheckStatus::OK);
        Assert::same($result->httpStatus, 599);
    }

    public function returnsCriticalAndLogsOnNetworkFailure(): void
    {
        $client = $this->failingClient(new ClientExceptionStub(message: 'reset'));
        $logger = $this->logger();

        $result = (new HttpContentCheckService(httpClient: $client, requestFactory: new FakeRequestFactory(), logger: $logger))
            ->check(url: 'https://example.com');

        Assert::same($result->status, CheckStatus::CRITICAL);
        Assert::same($result->httpStatus, 0);
        Assert::false($result->requiredTextFound);
        Assert::false($result->forbiddenTextFound);
        verify(fn() => $logger->error(Arg::any(), Arg::any()), times: 1);
        Assert::same($this->errorMessages->last(), 'reset');
        Assert::same($this->errorContexts->last(), ['url' => 'https://example.com/']);
    }

    public function appliesOptionsMethodHeadersAndDefaultUserAgent(): void
    {
        $client = $this->client(new FakeResponse(statusCode: 200, body: ''));
        $options = new HttpProbeOptions(method: 'POST', headers: ['X-Token' => 'secret'], userAgent: 'probe/1.0');

        (new HttpContentCheckService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(url: 'https://example.com', options: $options);

        $request = $this->requests->last();
        Assert::same($request->getMethod(), 'POST');
        Assert::same($request->getHeaderLine(name: 'X-Token'), 'secret');
        Assert::same($request->getHeaderLine(name: 'User-Agent'), 'probe/1.0');
    }

    public function checkFromResponseReturnsOkForMatchingStatusAndNoTextConstraints(): void
    {
        $response = new FakeResponse(statusCode: 200, body: 'anything');

        $result = (new HttpContentCheckService(
            httpClient: $this->client($response),
            requestFactory: new FakeRequestFactory(),
        ))->checkFromResponse(response: $response);

        Assert::same($result->status, CheckStatus::OK);
        Assert::same($result->httpStatus, 200);
    }

    public function checkFromResponseFindsRequiredText(): void
    {
        $response = new FakeResponse(statusCode: 200, body: 'hello world');

        $result = (new HttpContentCheckService(
            httpClient: $this->client($response),
            requestFactory: new FakeRequestFactory(),
        ))->checkFromResponse(response: $response, requiredText: 'hello');

        Assert::same($result->status, CheckStatus::OK);
        Assert::true($result->requiredTextFound);
    }

    public function checkFromResponseDetectsForbiddenText(): void
    {
        $response = new FakeResponse(statusCode: 200, body: 'blocked keyword');

        $result = (new HttpContentCheckService(
            httpClient: $this->client($response),
            requestFactory: new FakeRequestFactory(),
        ))->checkFromResponse(response: $response, forbiddenText: 'keyword');

        Assert::same($result->status, CheckStatus::CRITICAL);
        Assert::true($result->forbiddenTextFound);
    }

    public function checkFromResponseReturnsCriticalOnStatusMismatch(): void
    {
        $response = new FakeResponse(statusCode: 503, body: '');

        $result = (new HttpContentCheckService(
            httpClient: $this->client($response),
            requestFactory: new FakeRequestFactory(),
        ))->checkFromResponse(response: $response, expectedStatus: 200);

        Assert::same($result->status, CheckStatus::CRITICAL);
        Assert::same($result->httpStatus, 503);
    }

    public function checkFromResponseThrowsOnInvalidExpectedStatus(): void
    {
        try {
            (new HttpContentCheckService(
                httpClient: Understudy::for(ClientInterface::class),
                requestFactory: new FakeRequestFactory(),
            ))->checkFromResponse(response: new FakeResponse(), expectedStatus: 99);
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Invalid HTTP status 99');
        }
    }

    private function service(FakeResponse $response): HttpContentCheckService
    {
        return new HttpContentCheckService(
            httpClient: $this->client($response),
            requestFactory: new FakeRequestFactory(),
        );
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
