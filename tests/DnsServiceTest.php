<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\DomainMonitor\DnsService;

#[CoversClass(DnsService::class)]
final class DnsServiceTest extends TestCase
{
    #[Test]
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

        $this->assertSame('example.com', $received['host']);
        $this->assertSame(\DNS_A | \DNS_AAAA | \DNS_MX | \DNS_NS | \DNS_TXT | \DNS_CNAME, $received['type']);
        $this->assertSame(['1.2.3.4'], $records->a);
        $this->assertSame(['2001:db8::1'], $records->aaaa);
        $this->assertSame(['mx.example.com'], $records->mx);
        $this->assertSame(['ns1.example.com'], $records->ns);
        $this->assertSame(['v=spf1'], $records->txt);
        $this->assertSame(['alias.example.com'], $records->cname);
    }

    #[Test]
    public function deduplicatesRepeatedValues(): void
    {
        $records = (new DnsService(resolver: $this->resolver([
            ['type' => 'A', 'ip' => '1.2.3.4'],
            ['type' => 'A', 'ip' => '1.2.3.4'],
            ['type' => 'A', 'ip' => '5.6.7.8'],
        ])))->check(host: 'example.com');

        $this->assertSame(['1.2.3.4', '5.6.7.8'], $records->a);
    }

    #[Test]
    public function ignoresEmptyMissingAndNonStringValues(): void
    {
        $records = (new DnsService(resolver: $this->resolver([
            ['type' => 'A', 'ip' => ''],
            ['type' => 'A', 'ip' => 123],
            ['type' => 'A'],
            ['type' => 'A', 'ip' => '9.9.9.9'],
        ])))->check(host: 'example.com');

        $this->assertSame(['9.9.9.9'], $records->a);
    }

    #[Test]
    public function skipsRecordsWithMissingNonStringOrUnknownType(): void
    {
        $records = (new DnsService(resolver: $this->resolver([
            ['ip' => '1.2.3.4'],
            ['type' => 123, 'ip' => '5.6.7.8'],
            ['type' => 'UNKNOWN', 'ip' => '9.9.9.9'],
            ['type' => 'A', 'ip' => '8.8.8.8'],
        ])))->check(host: 'example.com');

        $this->assertSame(['8.8.8.8'], $records->a);
    }

    #[Test]
    public function returnsEmptyRecordsWhenResolverReturnsFalse(): void
    {
        $resolver = static function (string $host, int $type): false {
            unset($host, $type);

            return false;
        };

        $records = (new DnsService(resolver: $resolver))->check(host: 'example.com');

        $this->assertSame([], $records->a);
        $this->assertSame([], $records->mx);
    }

    #[Test]
    public function skipsRecordWithNonStringTypeButPresent(): void
    {
        $records = (new DnsService(resolver: $this->resolver([
            ['type' => 123, 'ip' => '5.6.7.8'],
            ['type' => 'A', 'ip' => '1.2.3.4'],
        ])))->check(host: 'example.com');

        $this->assertSame(['1.2.3.4'], $records->a);
    }

    #[Test]
    public function returnsEmptyRecordsWhenResolverThrows(): void
    {
        $resolver = static function (string $host, int $type): array {
            unset($host, $type);

            throw new \RuntimeException(message: 'DNS failure');
        };

        $records = (new DnsService(resolver: $resolver))->check(host: 'example.com');

        $this->assertSame([], $records->a);
        $this->assertSame([], $records->ns);
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
