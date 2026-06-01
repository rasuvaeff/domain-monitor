<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\PortService;

#[CoversNothing]
final class PortIntegrationTest extends TestCase
{
    #[Test]
    public function checksTcpPortAvailability(): void
    {
        if (\getenv('DOMAIN_MONITOR_NET') === false) {
            $this->markTestSkipped(message: 'Set DOMAIN_MONITOR_NET=1 to run network integration tests');
        }

        $result = (new PortService())->check(host: 'google.com', port: 443);

        $this->assertSame(CheckStatus::OK, $result->status);
    }
}
