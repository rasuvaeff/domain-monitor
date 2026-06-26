<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests\Integration;

use GuzzleHttp\Client as GuzzleClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\RobotsTxtService;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[CoversNothing]
final class RobotsTxtIntegrationTest
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

    public function findsRobotsTxt(): void
    {
        if (\getenv('DOMAIN_MONITOR_NET') === false) {
            return;
        }

        $service = new RobotsTxtService(httpClient: $this->httpClient, requestFactory: $this->requestFactory);
        $result = $service->check(baseUrl: 'https://example.com');

        Assert::same($result->status, CheckStatus::OK);
        Assert::true($result->exists);
        Assert::same($result->httpStatus, 200);
    }
}
