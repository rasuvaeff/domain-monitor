<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use InvalidArgumentException;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\HttpContentCheckService;
use Rasuvaeff\DomainMonitor\HttpProbeOptions;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\ClientExceptionStub;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeRequestFactory;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeResponse;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\RecordingHttpClient;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\RecordingLogger;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(HttpContentCheckService::class)]
final class HttpContentCheckServiceTest
{
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
        $client = new RecordingHttpClient(exception: new ClientExceptionStub(message: 'reset'));
        $logger = new RecordingLogger();

        $result = (new HttpContentCheckService(httpClient: $client, requestFactory: new FakeRequestFactory(), logger: $logger))
            ->check(url: 'https://example.com');

        Assert::same($result->status, CheckStatus::CRITICAL);
        Assert::same($result->httpStatus, 0);
        Assert::false($result->requiredTextFound);
        Assert::false($result->forbiddenTextFound);
        Assert::count($logger->records, 1);
        Assert::same($logger->records[0]['message'], 'reset');
        Assert::same($logger->records[0]['context'], ['url' => 'https://example.com/']);
    }

    public function appliesOptionsMethodHeadersAndDefaultUserAgent(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 200, body: ''));
        $options = new HttpProbeOptions(method: 'POST', headers: ['X-Token' => 'secret'], userAgent: 'probe/1.0');

        (new HttpContentCheckService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(url: 'https://example.com', options: $options);

        Assert::notNull($client->lastRequest);
        Assert::same($client->lastRequest->getMethod(), 'POST');
        Assert::same($client->lastRequest->getHeaderLine(name: 'X-Token'), 'secret');
        Assert::same($client->lastRequest->getHeaderLine(name: 'User-Agent'), 'probe/1.0');
    }

    public function checkFromResponseReturnsOkForMatchingStatusAndNoTextConstraints(): void
    {
        $response = new FakeResponse(statusCode: 200, body: 'anything');

        $result = (new HttpContentCheckService(
            httpClient: new RecordingHttpClient(response: $response),
            requestFactory: new FakeRequestFactory(),
        ))->checkFromResponse(response: $response);

        Assert::same($result->status, CheckStatus::OK);
        Assert::same($result->httpStatus, 200);
    }

    public function checkFromResponseFindsRequiredText(): void
    {
        $response = new FakeResponse(statusCode: 200, body: 'hello world');

        $result = (new HttpContentCheckService(
            httpClient: new RecordingHttpClient(response: $response),
            requestFactory: new FakeRequestFactory(),
        ))->checkFromResponse(response: $response, requiredText: 'hello');

        Assert::same($result->status, CheckStatus::OK);
        Assert::true($result->requiredTextFound);
    }

    public function checkFromResponseDetectsForbiddenText(): void
    {
        $response = new FakeResponse(statusCode: 200, body: 'blocked keyword');

        $result = (new HttpContentCheckService(
            httpClient: new RecordingHttpClient(response: $response),
            requestFactory: new FakeRequestFactory(),
        ))->checkFromResponse(response: $response, forbiddenText: 'keyword');

        Assert::same($result->status, CheckStatus::CRITICAL);
        Assert::true($result->forbiddenTextFound);
    }

    public function checkFromResponseReturnsCriticalOnStatusMismatch(): void
    {
        $response = new FakeResponse(statusCode: 503, body: '');

        $result = (new HttpContentCheckService(
            httpClient: new RecordingHttpClient(response: $response),
            requestFactory: new FakeRequestFactory(),
        ))->checkFromResponse(response: $response, expectedStatus: 200);

        Assert::same($result->status, CheckStatus::CRITICAL);
        Assert::same($result->httpStatus, 503);
    }

    public function checkFromResponseThrowsOnInvalidExpectedStatus(): void
    {
        try {
            (new HttpContentCheckService(
                httpClient: new RecordingHttpClient(),
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
            httpClient: new RecordingHttpClient(response: $response),
            requestFactory: new FakeRequestFactory(),
        );
    }
}
