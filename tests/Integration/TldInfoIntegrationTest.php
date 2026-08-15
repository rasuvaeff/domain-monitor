<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests\Integration;

use Iodev\Whois\Factory;
use Rasuvaeff\DomainMonitor\WhoisService;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;

#[Test]
#[CoversNothing]
final class TldInfoIntegrationTest
{
    public function loadsWhoisInformation(): void
    {
        if (\getenv('DOMAIN_MONITOR_NET') === false) {
            return;
        }

        $service = new WhoisService(whois: Factory::get()->createWhois());
        $info = $service->check(host: 'google.com');

        Assert::notNull($info);
        Assert::same($info->domain, 'google.com');
    }
}
