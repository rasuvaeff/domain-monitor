<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use Closure;

/**
 * @api
 */
final readonly class DnsService
{
    private const int RECORD_TYPES = \DNS_A | \DNS_AAAA | \DNS_MX | \DNS_NS | \DNS_TXT | \DNS_CNAME;

    private Closure $resolver;

    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver === null
            ? self::defaultResolver()
            : Closure::fromCallable(callback: $resolver);
    }

    public function check(string $host): DnsRecords
    {
        $normalizedHost = (new HostNormalizer())->normalizeHost(hostOrUrl: $host);
        $resolver = $this->resolver;

        try {
            /** @var array<int, array<string, mixed>>|false $records */
            $records = $resolver($normalizedHost, self::RECORD_TYPES);
        } catch (\Throwable) {
            return new DnsRecords();
        }

        if ($records === false) {
            return new DnsRecords();
        }

        $a = [];
        $aaaa = [];
        $mx = [];
        $ns = [];
        $txt = [];
        $cname = [];

        foreach ($records as $record) {
            $type = $record['type'] ?? null;

            if (!\is_string($type)) {
                continue;
            }

            match ($type) {
                'A' => $this->pushRecord(target: $a, value: $record['ip'] ?? null),
                'AAAA' => $this->pushRecord(target: $aaaa, value: $record['ipv6'] ?? null),
                'MX' => $this->pushRecord(target: $mx, value: $record['target'] ?? null),
                'NS' => $this->pushRecord(target: $ns, value: $record['target'] ?? null),
                'TXT' => $this->pushRecord(target: $txt, value: $record['txt'] ?? null),
                'CNAME' => $this->pushRecord(target: $cname, value: $record['target'] ?? null),
                default => null,
            };
        }

        return new DnsRecords(
            a: $a,
            aaaa: $aaaa,
            mx: $mx,
            ns: $ns,
            txt: $txt,
            cname: $cname,
        );
    }

    private static function defaultResolver(): Closure
    {
        return static fn(string $host, int $type): array|false => \dns_get_record(hostname: $host, type: $type);
    }

    /**
     * @param string[] $target
     */
    private function pushRecord(array &$target, mixed $value): void
    {
        if (\is_string($value) && $value !== '' && !\in_array(needle: $value, haystack: $target, strict: true)) {
            $target[] = $value;
        }
    }
}
