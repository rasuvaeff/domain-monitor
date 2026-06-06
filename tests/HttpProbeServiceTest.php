<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\DomainMonitor\HttpProbeOptions;
use Rasuvaeff\DomainMonitor\HttpProbeService;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\ClientExceptionStub;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeRequest;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeRequestFactory;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeResponse;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\RecordingHttpClient;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\RecordingLogger;

#[CoversClass(HttpProbeService::class)]
final class HttpProbeServiceTest extends TestCase
{
    #[Test]
    public function returnsStatusFromResponse(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 204));

        $result = (new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(url: 'https://example.com');

        $this->assertSame(204, $result->status);
        $this->assertGreaterThanOrEqual(0.0, $result->totalTime);
        $this->assertLessThan(10.0, $result->totalTime);
    }

    #[Test]
    public function appliesMethodHeadersAndDefaultUserAgent(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 200));

        (new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(url: 'https://example.com', options: new HttpProbeOptions(method: 'head', headers: ['X-Test' => '1']));

        $this->assertInstanceOf(FakeRequest::class, $client->lastRequest);
        $this->assertSame('HEAD', $client->lastRequest->getMethod());
        $this->assertSame('https://example.com/', $client->lastRequest->getUriString());
        $this->assertSame('1', $client->lastRequest->getHeaderLine(name: 'X-Test'));
        $this->assertSame('rasuvaeff/domain-monitor', $client->lastRequest->getHeaderLine(name: 'User-Agent'));
    }

    #[Test]
    public function keepsCustomUserAgentHeaderFromOptions(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 200));

        (new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(url: 'https://example.com', options: new HttpProbeOptions(headers: ['User-Agent' => 'custom-agent']));

        $this->assertNotNull($client->lastRequest);
        $this->assertSame('custom-agent', $client->lastRequest->getHeaderLine(name: 'User-Agent'));
    }

    #[Test]
    public function returnsStatusZeroAndLogsOnNetworkFailure(): void
    {
        $client = new RecordingHttpClient(exception: new ClientExceptionStub(message: 'down'));
        $logger = new RecordingLogger();

        $result = (new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory(), logger: $logger))
            ->check(url: 'https://example.com');

        $this->assertSame(0, $result->status);
        $this->assertGreaterThanOrEqual(0.0, $result->totalTime);
        $this->assertLessThan(10.0, $result->totalTime);
        $this->assertCount(1, $logger->records);
        $this->assertSame('down', $logger->records[0]['message']);
        $this->assertSame(['url' => 'https://example.com/'], $logger->records[0]['context']);
    }
}
