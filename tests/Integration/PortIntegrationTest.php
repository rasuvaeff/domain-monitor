<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests\Integration;

use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\PortService;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[CoversNothing]
final class PortIntegrationTest
{
    #[BeforeTest]
    public function setUp(): void
    {
        if (\getenv('DOMAIN_MONITOR_NET') === false) {
            return;
        }
    }

    public function checksTcpPortAvailability(): void
    {
        if (\getenv('DOMAIN_MONITOR_NET') === false) {
            return;
        }

        $result = (new PortService())->check(host: 'google.com', port: 443);

        Assert::same($result->status, CheckStatus::OK);
    }
}
