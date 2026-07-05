<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use Rasuvaeff\DomainMonitor\DnsRecords;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(DnsRecords::class)]
final class DnsRecordsTest
{
    public function defaultsToEmptyArrays(): void
    {
        $records = new DnsRecords();

        Assert::same($records->a, []);
        Assert::same($records->aaaa, []);
        Assert::same($records->mx, []);
        Assert::same($records->ns, []);
        Assert::same($records->txt, []);
        Assert::same($records->cname, []);
    }

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

        Assert::same($records->a, ['1.1.1.1']);
        Assert::same($records->aaaa, ['::1']);
        Assert::same($records->mx, ['mail.example.com']);
        Assert::same($records->ns, ['ns1.example.com']);
        Assert::same($records->txt, ['v=spf1']);
        Assert::same($records->cname, ['example.com']);
    }

    public function serializesEveryRecordGroup(): void
    {
        $records = new DnsRecords(
            a: ['1.2.3.4'],
            aaaa: ['::1'],
            mx: ['mail.example.com'],
            ns: ['ns1.example.com'],
            txt: ['v=spf1'],
            cname: ['example.com'],
        );

        Assert::same(
            $records->jsonSerialize(),
            [
                'a' => ['1.2.3.4'],
                'aaaa' => ['::1'],
                'mx' => ['mail.example.com'],
                'ns' => ['ns1.example.com'],
                'txt' => ['v=spf1'],
                'cname' => ['example.com'],
            ],
        );
    }
}
