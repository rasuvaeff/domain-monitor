<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests\Integration;

use Iodev\Whois\Factory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\DomainMonitor\WhoisService;

#[CoversNothing]
final class TldInfoIntegrationTest extends TestCase
{
    #[Test]
    public function loadsWhoisInformation(): void
    {
        if (\getenv('DOMAIN_MONITOR_NET') === false) {
            $this->markTestSkipped(message: 'Set DOMAIN_MONITOR_NET=1 to run network integration tests');
        }

        $service = new WhoisService(whois: Factory::get()->createWhois());
        $info = $service->check(host: 'google.com');

        $this->assertNotNull($info);
        $this->assertSame('google.com', $info->domain);
    }
}
