<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests\Integration;

use GuzzleHttp\Client as GuzzleClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\SitemapService;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[CoversNothing]
final class SitemapIntegrationTest
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

    public function checksSitemapAvailability(): void
    {
        if (\getenv('DOMAIN_MONITOR_NET') === false) {
            return;
        }

        $service = new SitemapService(httpClient: $this->httpClient, requestFactory: $this->requestFactory);
        $result = $service->check(sitemapUrl: 'https://www.example.com/sitemap.xml');

        Assert::same($result->status, CheckStatus::OK);
        Assert::true($result->exists);
        Assert::same($result->httpStatus, 200);
        Assert::true($result->urlCount >= 0);
    }
}
