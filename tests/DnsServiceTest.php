<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use Closure;
use Rasuvaeff\DomainMonitor\DnsService;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(DnsService::class)]
final class DnsServiceTest
{
    public function mapsEachRecordTypeToItsFieldAndForwardsHostAndTypes(): void
    {
        $received = ['host' => '', 'type' => 0];
        $resolver = function (string $host, int $type) use (&$received): array {
            $received = ['host' => $host, 'type' => $type];

            return [
                ['type' => 'A', 'ip' => '1.2.3.4'],
                ['type' => 'AAAA', 'ipv6' => '2001:db8::1'],
                ['type' => 'MX', 'target' => 'mx.example.com'],
                ['type' => 'NS', 'target' => 'ns1.example.com'],
                ['type' => 'TXT', 'txt' => 'v=spf1'],
                ['type' => 'CNAME', 'target' => 'alias.example.com'],
            ];
        };

        $records = (new DnsService(resolver: $resolver))->check(host: 'EXAMPLE.com');

        Assert::same($received['host'], 'example.com');
        Assert::same($received['type'], \DNS_A | \DNS_AAAA | \DNS_MX | \DNS_NS | \DNS_TXT | \DNS_CNAME);
        Assert::same($records->a, ['1.2.3.4']);
        Assert::same($records->aaaa, ['2001:db8::1']);
        Assert::same($records->mx, ['mx.example.com']);
        Assert::same($records->ns, ['ns1.example.com']);
        Assert::same($records->txt, ['v=spf1']);
        Assert::same($records->cname, ['alias.example.com']);
    }

    public function deduplicatesRepeatedValues(): void
    {
        $records = (new DnsService(resolver: $this->resolver([
            ['type' => 'A', 'ip' => '1.2.3.4'],
            ['type' => 'A', 'ip' => '1.2.3.4'],
            ['type' => 'A', 'ip' => '5.6.7.8'],
        ])))->check(host: 'example.com');

        Assert::same($records->a, ['1.2.3.4', '5.6.7.8']);
    }

    public function ignoresEmptyMissingAndNonStringValues(): void
    {
        $records = (new DnsService(resolver: $this->resolver([
            ['type' => 'A', 'ip' => ''],
            ['type' => 'A', 'ip' => 123],
            ['type' => 'A'],
            ['type' => 'A', 'ip' => '9.9.9.9'],
        ])))->check(host: 'example.com');

        Assert::same($records->a, ['9.9.9.9']);
    }

    public function skipsRecordsWithMissingNonStringOrUnknownType(): void
    {
        $records = (new DnsService(resolver: $this->resolver([
            ['ip' => '1.2.3.4'],
            ['type' => 123, 'ip' => '5.6.7.8'],
            ['type' => 'UNKNOWN', 'ip' => '9.9.9.9'],
            ['type' => 'A', 'ip' => '8.8.8.8'],
        ])))->check(host: 'example.com');

        Assert::same($records->a, ['8.8.8.8']);
    }

    public function returnsEmptyRecordsWhenResolverReturnsFalse(): void
    {
        $warnings = [];
        $prevHandler = \set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;

            return true;
        });

        try {
            $resolver = static function (string $host, int $type): false {
                unset($host, $type);

                return false;
            };

            $records = (new DnsService(resolver: $resolver))->check(host: 'example.com');

            Assert::same($records->a, []);
            Assert::same($records->mx, []);
            Assert::same($warnings, []);
        } finally {
            \set_error_handler($prevHandler);
        }
    }

    public function skipsRecordWithNonStringTypeButPresent(): void
    {
        $records = (new DnsService(resolver: $this->resolver([
            ['type' => 123, 'ip' => '5.6.7.8'],
            ['type' => 'A', 'ip' => '1.2.3.4'],
        ])))->check(host: 'example.com');

        Assert::same($records->a, ['1.2.3.4']);
    }

    public function returnsEmptyRecordsWhenResolverThrows(): void
    {
        $warnings = [];
        $prevHandler = \set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;

            return true;
        });

        try {
            $resolver = static function (string $host, int $type): array {
                unset($host, $type);

                throw new \RuntimeException(message: 'DNS failure');
            };

            $records = (new DnsService(resolver: $resolver))->check(host: 'example.com');

            Assert::same($records->a, []);
            Assert::same($records->ns, []);
            Assert::same($warnings, []);
        } finally {
            \set_error_handler($prevHandler);
        }
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    private function resolver(array $records): Closure
    {
        return static function (string $host, int $type) use ($records): array {
            unset($host, $type);

            return $records;
        };
    }
}
