<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use Closure;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\EmailSecurityService;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(EmailSecurityService::class)]
final class EmailSecurityServiceTest
{
    public function normalizesHostBeforeLookup(): void
    {
        $calls = [];

        $resolver = function (string $host, int $type) use (&$calls): array {
            $calls[] = $host;

            return [];
        };

        (new EmailSecurityService(resolver: $resolver))->check(host: 'HTTPS://Example.COM:8443/Path');

        Assert::same($calls, ['example.com', '_dmarc.example.com']);
    }

    public function returnsOkWhenAllPoliciesPublished(): void
    {
        $resolver = $this->resolver(
            root: [
                ['type' => 'TXT', 'txt' => 'v=spf1 include:_spf.example.com -all'],
                ['type' => 'MX', 'target' => 'mx1.example.com'],
                ['type' => 'CAA', 'tag' => 'issue', 'value' => 'letsencrypt.org'],
            ],
            dmarc: [['type' => 'TXT', 'txt' => 'v=DMARC1; p=reject; rua=mailto:dmarc@example.com']],
        );

        $check = (new EmailSecurityService(resolver: $resolver))->check(host: 'example.com');

        Assert::same($check->status, CheckStatus::OK);
        Assert::true($check->hasSpf);
        Assert::same($check->spfRecord, 'v=spf1 include:_spf.example.com -all');
        Assert::true($check->hasDmarc);
        Assert::same($check->dmarcPolicy, 'reject');
        Assert::same($check->mxRecords, ['mx1.example.com']);
        Assert::true($check->hasCaa);
        Assert::same($check->caaRecords, ['letsencrypt.org']);
    }

    public function returnsWarningWhenMxPresentButDmarcMissing(): void
    {
        $resolver = $this->resolver(
            root: [
                ['type' => 'TXT', 'txt' => 'v=spf1 -all'],
                ['type' => 'MX', 'target' => 'mx1.example.com'],
            ],
            dmarc: [],
        );

        $check = (new EmailSecurityService(resolver: $resolver))->check(host: 'example.com');

        Assert::same($check->status, CheckStatus::WARNING);
        Assert::true($check->hasSpf);
        Assert::false($check->hasDmarc);
        Assert::null($check->dmarcPolicy);
    }

    public function returnsCriticalWhenMxPresentAndNeitherSpfNorDmarc(): void
    {
        $resolver = $this->resolver(
            root: [['type' => 'MX', 'target' => 'mx1.example.com']],
            dmarc: [],
        );

        $check = (new EmailSecurityService(resolver: $resolver))->check(host: 'example.com');

        Assert::same($check->status, CheckStatus::CRITICAL);
        Assert::false($check->hasSpf);
        Assert::false($check->hasDmarc);
    }

    public function returnsOkWhenNoMailInfrastructure(): void
    {
        $resolver = $this->resolver(root: [], dmarc: []);

        $check = (new EmailSecurityService(resolver: $resolver))->check(host: 'example.com');

        Assert::same($check->status, CheckStatus::OK);
        Assert::false($check->hasSpf);
        Assert::false($check->hasDmarc);
        Assert::same($check->mxRecords, []);
    }

    public function returnsWarningWhenNoMxButPoliciesPartiallyMissing(): void
    {
        $resolver = $this->resolver(
            root: [['type' => 'TXT', 'txt' => 'v=spf1 -all']],
            dmarc: [],
        );

        $check = (new EmailSecurityService(resolver: $resolver))->check(host: 'example.com');

        Assert::same($check->status, CheckStatus::WARNING);
    }

    public function returnsUnknownWhenRootResolverThrows(): void
    {
        $resolver = static function (string $host, int $type): array {
            unset($type);

            if ($host === 'example.com') {
                throw new \RuntimeException(message: 'DNS failure');
            }

            return [];
        };

        $check = (new EmailSecurityService(resolver: $resolver))->check(host: 'example.com');

        Assert::same($check->status, CheckStatus::UNKNOWN);
        Assert::same($check->reason, 'DNS lookup failed');
    }

    public function returnsUnknownWhenRootResolverReturnsFalse(): void
    {
        $resolver = static fn(string $host, int $type): array|false => $host === 'example.com' ? false : [];

        $check = (new EmailSecurityService(resolver: $resolver))->check(host: 'example.com');

        Assert::same($check->status, CheckStatus::UNKNOWN);
    }

    public function treatsDmarcTxtWithoutPolicyAsPresent(): void
    {
        $resolver = $this->resolver(
            root: [['type' => 'MX', 'target' => 'mx1.example.com']],
            dmarc: [['type' => 'TXT', 'txt' => 'v=DMARC1; sp=none']],
        );

        $check = (new EmailSecurityService(resolver: $resolver))->check(host: 'example.com');

        Assert::true($check->hasDmarc);
        Assert::null($check->dmarcPolicy);
    }

    public function ignoresNonSpfTxtRecords(): void
    {
        $resolver = $this->resolver(
            root: [
                ['type' => 'TXT', 'txt' => 'google-site-verification=abc'],
                ['type' => 'TXT', 'txt' => 'random token'],
            ],
            dmarc: [],
        );

        $check = (new EmailSecurityService(resolver: $resolver))->check(host: 'example.com');

        Assert::false($check->hasSpf);
        Assert::null($check->spfRecord);
    }

    public function skipsCaaRecordsWithTagOtherThanIssue(): void
    {
        $resolver = $this->resolver(
            root: [
                ['type' => 'CAA', 'tag' => 'issuewild', 'value' => 'letsencrypt.org'],
                ['type' => 'CAA', 'tag' => 'iodef', 'value' => 'mailto:abuse@example.com'],
            ],
            dmarc: [],
        );

        $check = (new EmailSecurityService(resolver: $resolver))->check(host: 'example.com');

        Assert::false($check->hasCaa);
        Assert::same($check->caaRecords, []);
    }

    public function skipsDkimWhenNoSelectorsConfigured(): void
    {
        $resolver = $this->resolver(root: [], dmarc: []);

        $check = (new EmailSecurityService(resolver: $resolver))->check(host: 'example.com');

        Assert::false($check->hasDkim);
        Assert::same($check->dkimSelectorsFound, []);
    }

    public function findsDkimWhenSelectorResolves(): void
    {
        $resolver = $this->resolver(
            root: [],
            dmarc: [],
            dkimBySelector: ['google' => [['type' => 'TXT', 'txt' => 'v=DKIM1; k=rsa; p=...']]],
        );

        $check = (new EmailSecurityService(
            resolver: $resolver,
            dkimSelectors: ['default', 'google'],
        ))->check(host: 'example.com');

        Assert::true($check->hasDkim);
        Assert::same($check->dkimSelectorsFound, ['google']);
    }

    public function deduplicatesMxTargets(): void
    {
        $resolver = $this->resolver(
            root: [
                ['type' => 'MX', 'target' => 'mx1.example.com'],
                ['type' => 'MX', 'target' => 'mx1.example.com'],
                ['type' => 'MX', 'target' => 'mx2.example.com'],
            ],
            dmarc: [],
        );

        $check = (new EmailSecurityService(resolver: $resolver))->check(host: 'example.com');

        Assert::same($check->mxRecords, ['mx1.example.com', 'mx2.example.com']);
    }

    /**
     * @param array<int, array<string, mixed>>     $root
     * @param array<int, array<string, mixed>>     $dmarc
     * @param array<string, array<int, array<string, mixed>>> $dkimBySelector
     */
    private function resolver(array $root, array $dmarc, array $dkimBySelector = []): Closure
    {
        return static function (string $host, int $type) use ($root, $dmarc, $dkimBySelector): array {
            unset($type);

            return match ($host) {
                'example.com' => $root,
                '_dmarc.example.com' => $dmarc,
                default => $dkimBySelector[self::selectorOf(host: $host)] ?? [],
            };
        };
    }

    private static function selectorOf(string $host): ?string
    {
        if (!\str_ends_with(haystack: $host, needle: '._domainkey.example.com')) {
            return null;
        }

        return \substr(string: $host, offset: 0, length: -\strlen(string: '._domainkey.example.com'));
    }
}
