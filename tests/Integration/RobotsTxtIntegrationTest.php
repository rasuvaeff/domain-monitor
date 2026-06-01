<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests\Integration;

use GuzzleHttp\Client as GuzzleClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\RobotsTxtService;

#[CoversNothing]
final class RobotsTxtIntegrationTest extends TestCase
{
    private ClientInterface $httpClient;
    private RequestFactoryInterface $requestFactory;

    #[\Override]
    protected function setUp(): void
    {
        if (\getenv('DOMAIN_MONITOR_NET') === false) {
            $this->markTestSkipped(message: 'Set DOMAIN_MONITOR_NET=1 to run network integration tests');
        }

        $psr17Factory = new Psr17Factory();
        $this->requestFactory = $psr17Factory;
        $this->httpClient = new GuzzleClient();
    }

    #[Test]
    public function findsRobotsTxt(): void
    {
        $service = new RobotsTxtService(httpClient: $this->httpClient, requestFactory: $this->requestFactory);
        $result = $service->check(baseUrl: 'https://example.com');

        $this->assertSame(CheckStatus::OK, $result->status);
        $this->assertTrue($result->exists);
        $this->assertSame(200, $result->httpStatus);
    }
}
