<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests\Integration;

use Rasuvaeff\DomainMonitor\DnsService;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[CoversNothing]
final class DnsIntegrationTest
{
    #[BeforeTest]
    public function setUp(): void
    {
        if (\getenv('DOMAIN_MONITOR_NET') === false) {
            return;
        }
    }

    public function resolvesPublicDnsRecords(): void
    {
        if (\getenv('DOMAIN_MONITOR_NET') === false) {
            return;
        }

        $records = (new DnsService())->check(host: 'google.com');

        Assert::notSame($records->a, []);
        Assert::notSame($records->ns, []);
    }
}
