<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests\Fixtures;

use Rasuvaeff\DomainMonitor\DnsRecords;
use Rasuvaeff\DomainMonitor\DnsServiceInterface;

final class StubDnsService implements DnsServiceInterface
{
    public function __construct(
        public readonly array $a = ['9.9.9.9'],
    ) {}

    #[\Override]
    public function check(string $host): DnsRecords
    {
        return new DnsRecords(a: $this->a);
    }
}
