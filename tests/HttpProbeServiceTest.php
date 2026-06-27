<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use Rasuvaeff\DomainMonitor\HttpProbeOptions;
use Rasuvaeff\DomainMonitor\HttpProbeService;
use Rasuvaeff\DomainMonitor\HttpProbeWithResponse;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\ClientExceptionStub;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeRequest;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeRequestFactory;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeResponse;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\RecordingHttpClient;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\RecordingLogger;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(HttpProbeService::class)]
final class HttpProbeServiceTest
{
    public function returnsStatusFromResponse(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 204));

        $result = (new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(url: 'https://example.com');

        Assert::same($result->status, 204);
        Assert::true($result->totalTime >= 0.0);
        Assert::true($result->totalTime < 10.0);
    }

    public function appliesMethodHeadersAndDefaultUserAgent(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 200));

        (new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(url: 'https://example.com', options: new HttpProbeOptions(method: 'head', headers: ['X-Test' => '1']));

        Assert::instanceOf($client->lastRequest, FakeRequest::class);
        Assert::same($client->lastRequest->getMethod(), 'HEAD');
        Assert::same($client->lastRequest->getUriString(), 'https://example.com/');
        Assert::same($client->lastRequest->getHeaderLine(name: 'X-Test'), '1');
        Assert::same($client->lastRequest->getHeaderLine(name: 'User-Agent'), 'rasuvaeff/domain-monitor');
    }

    public function keepsCustomUserAgentHeaderFromOptions(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 200));

        (new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->check(url: 'https://example.com', options: new HttpProbeOptions(headers: ['User-Agent' => 'custom-agent']));

        Assert::notNull($client->lastRequest);
        Assert::same($client->lastRequest->getHeaderLine(name: 'User-Agent'), 'custom-agent');
    }

    public function returnsStatusZeroAndLogsOnNetworkFailure(): void
    {
        $client = new RecordingHttpClient(exception: new ClientExceptionStub(message: 'down'));
        $logger = new RecordingLogger();

        $result = (new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory(), logger: $logger))
            ->check(url: 'https://example.com');

        Assert::same($result->status, 0);
        Assert::true($result->totalTime >= 0.0);
        Assert::true($result->totalTime < 10.0);
        Assert::count($logger->records, 1);
        Assert::same($logger->records[0]['message'], 'down');
        Assert::same($logger->records[0]['context'], ['url' => 'https://example.com/']);
    }

    public function probeWithResponseReturnsResultAndResponse(): void
    {
        $response = new FakeResponse(statusCode: 200);
        $client = new RecordingHttpClient(response: $response);

        $result = (new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->probeWithResponse(url: 'https://example.com');

        Assert::instanceOf($result, HttpProbeWithResponse::class);
        Assert::same($result->result->status, 200);
        Assert::same($result->response, $response);
    }

    public function probeWithResponseAppliesOptionsAndMeasuresTime(): void
    {
        $client = new RecordingHttpClient(response: new FakeResponse(statusCode: 204));

        $result = (new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->probeWithResponse(
                url: 'https://example.com',
                options: new HttpProbeOptions(method: 'head', headers: ['X-Test' => '1']),
            );

        Assert::same($result->result->status, 204);
        Assert::true($result->result->totalTime >= 0.0);
        Assert::true($result->result->totalTime < 10.0);
        Assert::instanceOf($client->lastRequest, FakeRequest::class);
        Assert::same($client->lastRequest->getMethod(), 'HEAD');
        Assert::same($client->lastRequest->getHeaderLine(name: 'X-Test'), '1');
    }

    public function probeWithResponseThrowsOnNetworkFailure(): void
    {
        $client = new RecordingHttpClient(exception: new ClientExceptionStub(message: 'timeout'));

        Expect::exception(ClientExceptionStub::class);

        (new HttpProbeService(httpClient: $client, requestFactory: new FakeRequestFactory()))
            ->probeWithResponse(url: 'https://example.com');
    }
}
