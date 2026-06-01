<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\DomainMonitor\SslCertificate;

#[CoversClass(SslCertificate::class)]
final class SslCertificateTest extends TestCase
{
    #[Test]
    public function preservesFields(): void
    {
        $from = new DateTimeImmutable(datetime: '2026-01-01T00:00:00+00:00');
        $until = new DateTimeImmutable(datetime: '2026-04-01T00:00:00+00:00');
        $certificate = new SslCertificate(validFrom: $from, validUntil: $until, subjectCn: 'example.com', issuer: 'Test CA');

        $this->assertSame($from, $certificate->validFrom);
        $this->assertSame($until, $certificate->validUntil);
        $this->assertSame('example.com', $certificate->subjectCn);
        $this->assertSame('Test CA', $certificate->issuer);
    }

    #[Test]
    public function defaultsIssuerToNull(): void
    {
        $certificate = $this->certificate(validUntil: '2026-04-01T00:00:00+00:00');

        $this->assertNull($certificate->issuer);
    }

    #[Test]
    public function throwsOnEmptySubjectCn(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessage(message: 'Subject CN must not be empty');

        new SslCertificate(
            validFrom: new DateTimeImmutable(datetime: '2026-01-01T00:00:00+00:00'),
            validUntil: new DateTimeImmutable(datetime: '2026-04-01T00:00:00+00:00'),
            subjectCn: '',
        );
    }

    #[Test]
    public function calculatesDaysUntilExpiryWithFloor(): void
    {
        $certificate = $this->certificate(validUntil: '2026-01-11T18:00:00+00:00');

        $this->assertSame(
            10,
            $certificate->daysUntilExpiry(now: new DateTimeImmutable(datetime: '2026-01-01T00:00:00+00:00')),
        );
    }

    #[Test]
    public function reportsExpiredWhenValidUntilIsAtOrBeforeNow(): void
    {
        $certificate = $this->certificate(validUntil: '2026-01-10T00:00:00+00:00');

        $this->assertTrue($certificate->isExpired(now: new DateTimeImmutable(datetime: '2026-01-10T00:00:00+00:00')));
        $this->assertTrue($certificate->isExpired(now: new DateTimeImmutable(datetime: '2026-01-11T00:00:00+00:00')));
        $this->assertFalse($certificate->isExpired(now: new DateTimeImmutable(datetime: '2026-01-09T23:59:59+00:00')));
    }

    #[Test]
    public function evaluatesIsExpiringWithinThreshold(): void
    {
        $certificate = $this->certificate(validUntil: '2026-01-11T00:00:00+00:00');
        $now = new DateTimeImmutable(datetime: '2026-01-01T00:00:00+00:00');

        $this->assertTrue($certificate->isExpiringWithin(days: 10, now: $now));
        $this->assertFalse($certificate->isExpiringWithin(days: 9, now: $now));
    }

    #[Test]
    public function throwsWhenIsExpiringWithinReceivesNegativeDays(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessage(message: 'Days must be greater than or equal to 0');

        $this->certificate(validUntil: '2026-04-01T00:00:00+00:00')->isExpiringWithin(days: -1);
    }

    #[Test]
    public function usesCurrentTimeWhenNowOmitted(): void
    {
        $future = $this->certificate(validUntil: '2999-01-01T00:00:00+00:00');
        $past = $this->certificate(validUntil: '2000-01-01T00:00:00+00:00');

        $this->assertFalse($future->isExpired());
        $this->assertTrue($past->isExpired());
        $this->assertGreaterThan(0, $future->daysUntilExpiry());
    }

    private function certificate(string $validUntil): SslCertificate
    {
        return new SslCertificate(
            validFrom: new DateTimeImmutable(datetime: '2000-01-01T00:00:00+00:00'),
            validUntil: new DateTimeImmutable(datetime: $validUntil),
            subjectCn: 'example.com',
        );
    }
}
