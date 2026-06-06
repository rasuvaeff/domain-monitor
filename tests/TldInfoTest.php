<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\DomainMonitor\TldInfo;

#[CoversClass(TldInfo::class)]
final class TldInfoTest extends TestCase
{
    #[Test]
    public function preservesFields(): void
    {
        $expiration = new DateTimeImmutable(datetime: '2026-01-11T00:00:00+00:00');
        $tldInfo = new TldInfo(
            domain: 'example.com',
            registrar: 'Registrar',
            expirationDate: $expiration,
            states: ['ok', 'clientHold'],
        );

        $this->assertSame('example.com', $tldInfo->domain);
        $this->assertSame('Registrar', $tldInfo->registrar);
        $this->assertSame($expiration, $tldInfo->expirationDate);
        $this->assertSame(['ok', 'clientHold'], $tldInfo->states);
    }

    #[Test]
    public function defaultsOptionalFields(): void
    {
        $tldInfo = new TldInfo(domain: 'example.com');

        $this->assertNull($tldInfo->registrar);
        $this->assertNull($tldInfo->expirationDate);
        $this->assertSame([], $tldInfo->states);
    }

    #[Test]
    public function calculatesFullDaysUntilExpiry(): void
    {
        $tldInfo = new TldInfo(
            domain: 'example.com',
            expirationDate: new DateTimeImmutable(datetime: '2026-01-11T12:00:00+00:00'),
        );

        $this->assertSame(
            10,
            $tldInfo->daysUntilExpiry(now: new DateTimeImmutable(datetime: '2026-01-01T00:00:00+00:00')),
        );
    }

    #[Test]
    public function calculatesDaysWithExactDivisorBoundary(): void
    {
        $tldInfo = new TldInfo(
            domain: 'example.com',
            expirationDate: new DateTimeImmutable(datetime: '2026-01-02T23:59:59+00:00'),
        );

        $this->assertSame(
            1,
            $tldInfo->daysUntilExpiry(now: new DateTimeImmutable(datetime: '2026-01-01T00:00:00+00:00')),
        );
    }

    #[Test]
    public function returnsNegativeDaysWhenExpired(): void
    {
        $tldInfo = new TldInfo(
            domain: 'example.com',
            expirationDate: new DateTimeImmutable(datetime: '2026-01-01T00:00:00+00:00'),
        );

        $this->assertSame(
            -5,
            $tldInfo->daysUntilExpiry(now: new DateTimeImmutable(datetime: '2026-01-06T00:00:00+00:00')),
        );
    }

    #[Test]
    public function returnsNullWhenExpirationDateIsMissing(): void
    {
        $tldInfo = new TldInfo(domain: 'example.com');

        $this->assertNull($tldInfo->daysUntilExpiry(now: new DateTimeImmutable(datetime: '2026-01-01T00:00:00+00:00')));
    }
}
