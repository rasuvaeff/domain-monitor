<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\DomainMonitor\DnsService;

#[CoversNothing]
final class DnsIntegrationTest extends TestCase
{
    #[Test]
    public function resolvesPublicDnsRecords(): void
    {
        if (\getenv('DOMAIN_MONITOR_NET') === false) {
            $this->markTestSkipped(message: 'Set DOMAIN_MONITOR_NET=1 to run network integration tests');
        }

        $records = (new DnsService())->check(host: 'google.com');

        $this->assertNotSame([], $records->a);
        $this->assertNotSame([], $records->ns);
    }
}
