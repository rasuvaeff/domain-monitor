<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\EmailSecurityCheck;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(EmailSecurityCheck::class)]
final class EmailSecurityCheckTest
{
    public function preservesAllFields(): void
    {
        $check = new EmailSecurityCheck(
            status: CheckStatus::OK,
            hasSpf: true,
            spfRecord: 'v=spf1 -all',
            hasDmarc: true,
            dmarcPolicy: 'reject',
            hasDkim: true,
            dkimSelectorsFound: ['google', 'default'],
            hasCaa: true,
            caaRecords: ['letsencrypt.org'],
            mxRecords: ['mx1.example.com'],
        );

        Assert::same($check->status, CheckStatus::OK);
        Assert::true($check->hasSpf);
        Assert::same($check->spfRecord, 'v=spf1 -all');
        Assert::true($check->hasDmarc);
        Assert::same($check->dmarcPolicy, 'reject');
        Assert::true($check->hasDkim);
        Assert::same($check->dkimSelectorsFound, ['google', 'default']);
        Assert::true($check->hasCaa);
        Assert::same($check->caaRecords, ['letsencrypt.org']);
        Assert::same($check->mxRecords, ['mx1.example.com']);
        Assert::null($check->reason);
    }

    public function defaultsOptionalFieldsToEmptyOrNull(): void
    {
        $check = new EmailSecurityCheck(status: CheckStatus::CRITICAL, hasSpf: false);

        Assert::null($check->spfRecord);
        Assert::false($check->hasDmarc);
        Assert::null($check->dmarcPolicy);
        Assert::false($check->hasDkim);
        Assert::same($check->dkimSelectorsFound, []);
        Assert::false($check->hasCaa);
        Assert::same($check->caaRecords, []);
        Assert::same($check->mxRecords, []);
        Assert::null($check->reason);
    }

    public function serializesToArray(): void
    {
        $check = new EmailSecurityCheck(
            status: CheckStatus::WARNING,
            hasSpf: true,
            spfRecord: 'v=spf1 -all',
            hasDmarc: false,
            hasDkim: false,
            dkimSelectorsFound: [],
            hasCaa: false,
            caaRecords: [],
            mxRecords: ['mx1.example.com'],
            reason: 'Mail accepted but DMARC missing',
        );

        Assert::same(
            $check->jsonSerialize(),
            [
                'status' => 'warning',
                'hasSpf' => true,
                'spfRecord' => 'v=spf1 -all',
                'hasDmarc' => false,
                'dmarcPolicy' => null,
                'hasDkim' => false,
                'dkimSelectorsFound' => [],
                'hasCaa' => false,
                'caaRecords' => [],
                'mxRecords' => ['mx1.example.com'],
                'reason' => 'Mail accepted but DMARC missing',
            ],
        );
    }

    public function roundtripsThroughJson(): void
    {
        $check = new EmailSecurityCheck(
            status: CheckStatus::OK,
            hasSpf: true,
            spfRecord: 'v=spf1 -all',
            hasDmarc: true,
            dmarcPolicy: 'reject',
            hasDkim: true,
            dkimSelectorsFound: ['google'],
            hasCaa: true,
            caaRecords: ['letsencrypt.org'],
            mxRecords: ['mx1.example.com', 'mx2.example.com'],
        );

        $decoded = \json_decode(\json_encode($check, JSON_THROW_ON_ERROR), associative: true, flags: JSON_THROW_ON_ERROR);

        Assert::same($decoded['status'], 'ok');
        Assert::true($decoded['hasSpf']);
        Assert::same($decoded['mxRecords'], ['mx1.example.com', 'mx2.example.com']);
    }
}
