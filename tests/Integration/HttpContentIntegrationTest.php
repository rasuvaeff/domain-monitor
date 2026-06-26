<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests\Integration;

use GuzzleHttp\Client as GuzzleClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\HttpContentCheckService;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[CoversNothing]
final class HttpContentIntegrationTest
{
    private ClientInterface $httpClient;
    private RequestFactoryInterface $requestFactory;

    #[BeforeTest]
    public function setUp(): void
    {
        if (\getenv('DOMAIN_MONITOR_NET') === false) {
            return;
        }

        $psr17Factory = new Psr17Factory();
        $this->requestFactory = $psr17Factory;
        $this->httpClient = new GuzzleClient();
    }

    public function checksValidUrlStatusAndContent(): void
    {
        if (\getenv('DOMAIN_MONITOR_NET') === false) {
            return;
        }

        $service = new HttpContentCheckService(httpClient: $this->httpClient, requestFactory: $this->requestFactory);
        $result = $service->check(url: 'https://example.com', requiredText: 'Example Domain');

        Assert::same($result->status, CheckStatus::OK);
        Assert::same($result->httpStatus, 200);
        Assert::true($result->requiredTextFound);
    }

    public function detectsMissingRequiredText(): void
    {
        if (\getenv('DOMAIN_MONITOR_NET') === false) {
            return;
        }

        $service = new HttpContentCheckService(httpClient: $this->httpClient, requestFactory: $this->requestFactory);
        $result = $service->check(url: 'https://example.com', requiredText: 'DefinitelyNotPresent123XYZ');

        Assert::same($result->status, CheckStatus::CRITICAL);
        Assert::false($result->requiredTextFound);
    }

    public function detectsWrongExpectedStatus(): void
    {
        if (\getenv('DOMAIN_MONITOR_NET') === false) {
            return;
        }

        $service = new HttpContentCheckService(httpClient: $this->httpClient, requestFactory: $this->requestFactory);
        $result = $service->check(url: 'https://example.com', expectedStatus: 201);

        Assert::same($result->status, CheckStatus::CRITICAL);
        Assert::same($result->httpStatus, 200);
    }
}
