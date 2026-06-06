<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\HttpContentCheckService;
use Rasuvaeff\DomainMonitor\HttpProbeOptions;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\ClientExceptionStub;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeRequestFactory;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeResponse;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\RecordingHttpClient;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\RecordingLogger;

#[CoversClass(HttpContentCheckService::class)]
final class HttpContentCheckServiceTest extends TestCase
{
    #[Test]
    public function returnsOkWhenStatusMatchesAndNoTextConstraints(): void
    {
        $service = $this->service(new FakeResponse(statusCode: 200, body: 'anything'));

        $result = $service->check(url: 'https://example.com');

        $this->assertSame(CheckStatus::OK, $result->status);
        $this->assertSame(200, $result->httpStatus);
        $this->assertNull($result->finalUrl);
        $this->assertTrue($result->requiredTextFound);
        $this->assertFalse($result->forbiddenTextFound);
    }

    #[Test]
    public function returnsOkWhenRequiredTextFoundAndForbiddenAbsent(): void
    {
        $service = $this->service(new FakeResponse(statusCode: 200, body: 'hello world'));

        $result = $service->check(url: 'https://example.com', requiredText: 'hello', forbiddenText: 'error');

        $this->assertSame(CheckStatus::OK, $result->status);
        $this->assertTrue($result->requiredTextFound);
        $this->assertFalse($result->forbiddenTextFound);
    }

    #[Test]
    public function returnsCriticalWhenRequiredTextMissing(): void
    {
        $service = $this->service(new FakeResponse(statusCode: 200, body: 'goodbye'));

        $result = $service->check(url: 'https://example.com', requiredText: 'hello');

        $this->assertSame(CheckStatus::CRITICAL, $result->status);
        $this->assertFalse($result->requiredTextFound);
    }

    #[Test]
    public function returnsCriticalWhenForbiddenTextFound(): void
    {
        $service = $this->service(new FakeResponse(statusCode: 200, body: 'blocked keyword here'));

        $result = $service->check(url: 'https://example.com', forbiddenText: 'keyword');

        $this->assertSame(CheckStatus::CRITICAL, $result->status);
        $this->assertTrue($result->forbiddenTextFound);
    }

    #[Test]
    public function returnsCriticalWhenStatusDiffersFromExpected(): void
    {
        $service = $this->service(new FakeResponse(statusCode: 500, body: 'hello'));

        $result = $service->check(url: 'https://example.com', requiredText: 'hello');

        $this->assertSame(CheckStatus::CRITICAL, $result->status);
        $this->assertSame(500, $result->httpStatus);
        $this->assertTrue($result->requiredTextFound);
    }

    #[Test]
    public function honorsCustomExpectedStatus(): void
    {
        $service = $this->service(new FakeResponse(statusCode: 301, body: ''));

        $result = $service->check(url: 'https://example.com', expectedStatus: 301);

        $this->assertSame(CheckStatus::OK, $result->status);
        $this->assertSame(301, $result->httpStatus);
    }

    #[Test]
    #[DataProvider('invalidExpectedStatusProvider')]
    public function throwsOnInvalidExpectedStatus(int $expectedStatus): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessage(message: \sprintf('Invalid HTTP status %d', $expectedStatus));

        $this->service(new FakeResponse())->check(url: 'https://example.com', expectedStatus: $expectedStatus);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidExpectedStatusProvider(): iterable
    {
        yield 'below range' => [99];
        yield 'above range' => [600];
    }

    #[Test]
    public function acceptsBoundaryExpectedStatus100(): void
    {
        $service = $this->service(new FakeResponse(statusCode: 100, body: ''));

        $result = $service->check(url: 'https://example.com', expectedStatus: 100);

        $this->assertSame(CheckStatus::OK, $result->status);
        $this->assertSame(100, $result->httpStatus);
    }

    #[Test]
    public function acceptsBoundaryExpectedStatus599(): void
    {
        $service = $this->service(new FakeResponse(statusCode: 599, body: ''));

        $result = $service->check(url: 'https://example.com', expectedStatus: 599);

        $this->assertSame(CheckStatus::OK, $result->status);
        $this->assertSame(599, $result->httpStatus);
    }

    #[Test]
    public function returnsCriticalAndLogsOnNetworkFailure(): void
    {
        $client = new RecordingHttpClient(exception: new ClientExceptionStub(message: 'reset'));
        $logger = new RecordingLogger();

        $result = (new HttpContentCheckService(httpClient: $client, requestFactory: new FakeRequestFactory(), logger: $logger))
            ->check(url: 'https://example.com');

        $this->assertSame(CheckStatus::CRITICAL, $result->status);
        $this->assertSame(0, $result->httpStatus);
        $this->assertFalse($result->requiredTextFound);
        $this->assertFalse($result->forbiddenTextFound);
        $this->assertCount(1, $logger->records);
        $this->assertSame('reset', $logger->records[0]['message']);
        $this->assertSame(['url' => 'https://example.com/'], $logger->records[0]['context']);
    }

    #[Test]
    public function appliesOptionsMethodHeadersAndDefaultUserAgent(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 200, body: ''));
        $options = new HttpProbeOptions(method: 'POST', headers: ['X-Token' => 'secret'], userAgent: 'probe/1.0');

        (new HttpContentCheckService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(url: 'https://example.com', options: $options);

        $this->assertNotNull($client->lastRequest);
        $this->assertSame('POST', $client->lastRequest->getMethod());
        $this->assertSame('secret', $client->lastRequest->getHeaderLine(name: 'X-Token'));
        $this->assertSame('probe/1.0', $client->lastRequest->getHeaderLine(name: 'User-Agent'));
    }

    private function service(FakeResponse $response): HttpContentCheckService
    {
        return new HttpContentCheckService(
            httpClient: new RecordingHttpClient(response: $response),
            requestFactory: new FakeRequestFactory(),
        );
    }
}
