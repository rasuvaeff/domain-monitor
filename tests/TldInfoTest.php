<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use DateTimeImmutable;
use Rasuvaeff\DomainMonitor\TldInfo;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(TldInfo::class)]
final class TldInfoTest
{
    public function preservesFields(): void
    {
        $expiration = new DateTimeImmutable(datetime: '2026-01-11T00:00:00+00:00');
        $tldInfo = new TldInfo(
            domain: 'example.com',
            registrar: 'Registrar',
            expirationDate: $expiration,
            states: ['ok', 'clientHold'],
        );

        Assert::same($tldInfo->domain, 'example.com');
        Assert::same($tldInfo->registrar, 'Registrar');
        Assert::same($tldInfo->expirationDate, $expiration);
        Assert::same($tldInfo->states, ['ok', 'clientHold']);
    }

    public function defaultsOptionalFields(): void
    {
        $tldInfo = new TldInfo(domain: 'example.com');

        Assert::null($tldInfo->registrar);
        Assert::null($tldInfo->expirationDate);
        Assert::same($tldInfo->states, []);
    }

    public function calculatesFullDaysUntilExpiry(): void
    {
        $tldInfo = new TldInfo(
            domain: 'example.com',
            expirationDate: new DateTimeImmutable(datetime: '2026-01-11T12:00:00+00:00'),
        );

        Assert::same(
            $tldInfo->daysUntilExpiry(now: new DateTimeImmutable(datetime: '2026-01-01T00:00:00+00:00')),
            10,
        );
    }

    public function calculatesDaysWithExactDivisorBoundary(): void
    {
        $tldInfo = new TldInfo(
            domain: 'example.com',
            expirationDate: new DateTimeImmutable(datetime: '2026-01-02T23:59:59+00:00'),
        );

        Assert::same(
            $tldInfo->daysUntilExpiry(now: new DateTimeImmutable(datetime: '2026-01-01T00:00:00+00:00')),
            1,
        );
    }

    public function returnsNegativeDaysWhenExpired(): void
    {
        $tldInfo = new TldInfo(
            domain: 'example.com',
            expirationDate: new DateTimeImmutable(datetime: '2026-01-01T00:00:00+00:00'),
        );

        Assert::same(
            $tldInfo->daysUntilExpiry(now: new DateTimeImmutable(datetime: '2026-01-06T00:00:00+00:00')),
            -5,
        );
    }

    public function returnsNullWhenExpirationDateIsMissing(): void
    {
        $tldInfo = new TldInfo(domain: 'example.com');

        Assert::null($tldInfo->daysUntilExpiry(now: new DateTimeImmutable(datetime: '2026-01-01T00:00:00+00:00')));
    }
}
