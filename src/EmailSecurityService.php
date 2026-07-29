<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use Closure;

/**
 * Inspects the email-security DNS posture of a host: SPF, DMARC, DKIM (when
 * selectors are supplied), CAA and MX. Returns an immutable
 * {@see EmailSecurityCheck} whose `status` follows the worst-wins convention:
 *
 * - `OK` — both SPF and DMARC are published (or the host has no MX and no
 *   SPF/DMARC, i.e. it is not configured for inbound email at all).
 * - `WARNING` — mail is accepted (MX present) but SPF or DMARC is missing.
 * - `CRITICAL` — mail is accepted but neither SPF nor DMARC is published.
 * - `UNKNOWN` — the DNS lookup failed.
 *
 * @api
 */
final readonly class EmailSecurityService
{
    private const int RECORD_TYPES = \DNS_TXT | \DNS_MX | \DNS_CAA;

    private Closure $resolver;

    /**
     * @param list<string>|null $dkimSelectors selectors probed as `{selector}._domainkey.{host}`; null skips DKIM
     */
    public function __construct(
        ?callable $resolver = null,
        private ?array $dkimSelectors = null,
    ) {
        $this->resolver = $resolver === null
            ? $this->defaultResolver()
            : Closure::fromCallable(callback: $resolver);
    }

    public function check(string $host): EmailSecurityCheck
    {
        $normalizedHost = (new HostNormalizer())->normalizeHost(hostOrUrl: $host);
        $resolver = $this->resolver;

        $rootRecords = $this->safeResolve($resolver, $normalizedHost, self::RECORD_TYPES);

        if ($rootRecords === null) {
            return new EmailSecurityCheck(
                status: CheckStatus::UNKNOWN,
                hasSpf: false,
                reason: 'DNS lookup failed',
            );
        }

        $rootTxt = $this->txtValues($rootRecords);
        $mx = $this->mxTargets($rootRecords);
        $caa = $this->caaIssuers($rootRecords);

        [$spfRecord, $hasSpf] = $this->findSpf($rootTxt);
        [$dmarcPolicy, $hasDmarc] = $this->findDmarc($resolver, $normalizedHost);

        [$dkimSelectorsFound, $hasDkim] = $this->findDkim(
            resolver: $resolver,
            host: $normalizedHost,
        );

        return new EmailSecurityCheck(
            status: $this->resolveStatus(hasSpf: $hasSpf, hasDmarc: $hasDmarc, hasMx: $mx !== []),
            hasSpf: $hasSpf,
            spfRecord: $spfRecord,
            hasDmarc: $hasDmarc,
            dmarcPolicy: $dmarcPolicy,
            hasDkim: $hasDkim,
            dkimSelectorsFound: $dkimSelectorsFound,
            hasCaa: $caa !== [],
            caaRecords: $caa,
            mxRecords: $mx,
        );
    }

    private function defaultResolver(): Closure
    {
        return static fn(string $host, int $type): array|false => \dns_get_record(hostname: $host, type: $type);
    }

    /**
     * @return array<int, array<string, mixed>>|null null on resolver failure
     */
    private function safeResolve(Closure $resolver, string $host, int $type): ?array
    {
        try {
            /** @var array<int, array<string, mixed>>|false $records */
            $records = $resolver($host, $type);
        } catch (\Throwable) {
            return null;
        }

        if ($records === false) {
            return null;
        }

        return $records;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     *
     * @return list<string>
     */
    private function txtValues(array $records): array
    {
        $values = [];

        foreach ($records as $record) {
            $type = $record['type'] ?? null;

            if (!\is_string($type) || $type !== 'TXT') {
                continue;
            }

            $this->pushString(target: $values, value: $record['txt'] ?? null);
        }

        return $values;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     *
     * @return list<string>
     */
    private function mxTargets(array $records): array
    {
        $targets = [];

        foreach ($records as $record) {
            $type = $record['type'] ?? null;

            if (!\is_string($type) || $type !== 'MX') {
                continue;
            }

            $this->pushString(target: $targets, value: $record['target'] ?? null);
        }

        return $targets;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     *
     * @return list<string>
     */
    private function caaIssuers(array $records): array
    {
        $issuers = [];

        foreach ($records as $record) {
            $type = $record['type'] ?? null;

            if (!\is_string($type) || $type !== 'CAA') {
                continue;
            }

            $tag = $record['tag'] ?? null;

            if (!\is_string($tag) || \strtolower($tag) !== 'issue') {
                continue;
            }

            $this->pushString(target: $issuers, value: $record['value'] ?? null);
        }

        return $issuers;
    }

    /**
     * @param list<string> $target
     */
    private function pushString(array &$target, mixed $value): void
    {
        if (\is_string($value) && $value !== '' && !\in_array(needle: $value, haystack: $target, strict: true)) {
            $target[] = $value;
        }
    }

    /**
     * @param list<string> $txtValues
     *
     * @return array{0: ?string, 1: bool} [record, hasSpf]
     */
    private function findSpf(array $txtValues): array
    {
        foreach ($txtValues as $value) {
            if (\preg_match(pattern: '/^v=spf1\b/i', subject: $value) === 1) {
                return [$value, true];
            }
        }

        return [null, false];
    }

    /**
     * @return array{0: ?string, 1: bool} [policy, hasDmarc]
     */
    private function findDmarc(Closure $resolver, string $host): array
    {
        $records = $this->safeResolve($resolver, '_dmarc.' . $host, \DNS_TXT);

        if ($records === null) {
            return [null, false];
        }

        foreach ($this->txtValues($records) as $value) {
            if (\preg_match(pattern: '/^v=dmarc1\b/i', subject: $value) !== 1) {
                continue;
            }

            if (\preg_match(pattern: '/\bp=([a-z]+)/i', subject: $value, matches: $matches) === 1) {
                return [\strtolower($matches[1]), true];
            }

            return [null, true];
        }

        return [null, false];
    }

    /**
     * @return array{0: list<string>, 1: bool} [selectors, hasDkim]
     */
    private function findDkim(Closure $resolver, string $host): array
    {
        if ($this->dkimSelectors === null || $this->dkimSelectors === []) {
            return [[], false];
        }

        $found = [];

        foreach ($this->dkimSelectors as $selector) {
            $records = $this->safeResolve($resolver, $selector . '._domainkey.' . $host, \DNS_TXT);

            if ($records === null) {
                continue;
            }

            if ($this->txtValues($records) !== []) {
                $found[] = $selector;
            }
        }

        return [$found, $found !== []];
    }

    private function resolveStatus(bool $hasSpf, bool $hasDmarc, bool $hasMx): CheckStatus
    {
        if (!$hasMx && !$hasSpf && !$hasDmarc) {
            return CheckStatus::OK;
        }

        if (!$hasMx) {
            return $hasSpf && $hasDmarc ? CheckStatus::OK : CheckStatus::WARNING;
        }

        if ($hasSpf && $hasDmarc) {
            return CheckStatus::OK;
        }

        if ($hasSpf || $hasDmarc) {
            return CheckStatus::WARNING;
        }

        return CheckStatus::CRITICAL;
    }
}
