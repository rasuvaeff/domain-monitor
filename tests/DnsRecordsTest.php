<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\DomainMonitor\DnsRecords;

#[CoversClass(DnsRecords::class)]
final class DnsRecordsTest extends TestCase
{
    #[Test]
    public function defaultsToEmptyArrays(): void
    {
        $records = new DnsRecords();

        $this->assertSame([], $records->a);
        $this->assertSame([], $records->aaaa);
        $this->assertSame([], $records->mx);
        $this->assertSame([], $records->ns);
        $this->assertSame([], $records->txt);
        $this->assertSame([], $records->cname);
    }

    #[Test]
    public function preservesProvidedRecords(): void
    {
        $records = new DnsRecords(
            a: ['1.1.1.1'],
            aaaa: ['::1'],
            mx: ['mail.example.com'],
            ns: ['ns1.example.com'],
            txt: ['v=spf1'],
            cname: ['example.com'],
        );

        $this->assertSame(['1.1.1.1'], $records->a);
        $this->assertSame(['::1'], $records->aaaa);
        $this->assertSame(['mail.example.com'], $records->mx);
        $this->assertSame(['ns1.example.com'], $records->ns);
        $this->assertSame(['v=spf1'], $records->txt);
        $this->assertSame(['example.com'], $records->cname);
    }
}
