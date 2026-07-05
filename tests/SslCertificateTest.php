<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Rasuvaeff\DomainMonitor\SslCertificate;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(SslCertificate::class)]
final class SslCertificateTest
{
    public function preservesFields(): void
    {
        $from = new DateTimeImmutable(datetime: '2026-01-01T00:00:00+00:00');
        $until = new DateTimeImmutable(datetime: '2026-04-01T00:00:00+00:00');
        $certificate = new SslCertificate(validFrom: $from, validUntil: $until, subjectCn: 'example.com', issuer: 'Test CA');

        Assert::same($certificate->validFrom, $from);
        Assert::same($certificate->validUntil, $until);
        Assert::same($certificate->subjectCn, 'example.com');
        Assert::same($certificate->issuer, 'Test CA');
    }

    public function defaultsIssuerToNull(): void
    {
        $certificate = $this->certificate(validUntil: '2026-04-01T00:00:00+00:00');

        Assert::null($certificate->issuer);
    }

    public function throwsOnEmptySubjectCn(): void
    {
        try {
            new SslCertificate(
                validFrom: new DateTimeImmutable(datetime: '2026-01-01T00:00:00+00:00'),
                validUntil: new DateTimeImmutable(datetime: '2026-04-01T00:00:00+00:00'),
                subjectCn: '',
            );
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Subject CN must not be empty');
        }
    }

    public function calculatesDaysUntilExpiryWithFloor(): void
    {
        $certificate = $this->certificate(validUntil: '2026-01-11T18:00:00+00:00');

        Assert::same(
            $certificate->daysUntilExpiry(now: new DateTimeImmutable(datetime: '2026-01-01T00:00:00+00:00')),
            10,
        );
    }

    public function calculatesDaysUntilExpiryWithExactDivisorBoundary(): void
    {
        $certificate = new SslCertificate(
            validFrom: new DateTimeImmutable(datetime: '2026-01-01T00:00:00+00:00'),
            validUntil: new DateTimeImmutable(datetime: '2026-01-02T23:59:59+00:00'),
            subjectCn: 'example.com',
        );

        Assert::same(
            $certificate->daysUntilExpiry(now: new DateTimeImmutable(datetime: '2026-01-01T00:00:00+00:00')),
            1,
        );
    }

    public function reportsExpiredWhenValidUntilIsAtOrBeforeNow(): void
    {
        $certificate = $this->certificate(validUntil: '2026-01-10T00:00:00+00:00');

        Assert::true($certificate->isExpired(now: new DateTimeImmutable(datetime: '2026-01-10T00:00:00+00:00')));
        Assert::true($certificate->isExpired(now: new DateTimeImmutable(datetime: '2026-01-11T00:00:00+00:00')));
        Assert::false($certificate->isExpired(now: new DateTimeImmutable(datetime: '2026-01-09T23:59:59+00:00')));
    }

    public function evaluatesIsExpiringWithinThreshold(): void
    {
        $certificate = $this->certificate(validUntil: '2026-01-11T00:00:00+00:00');
        $now = new DateTimeImmutable(datetime: '2026-01-01T00:00:00+00:00');

        Assert::true($certificate->isExpiringWithin(days: 10, now: $now));
        Assert::false($certificate->isExpiringWithin(days: 9, now: $now));
    }

    public function isExpiringWithinAcceptsZeroDays(): void
    {
        $certificate = $this->certificate(validUntil: '2026-01-11T00:00:00+00:00');
        $now = new DateTimeImmutable(datetime: '2026-01-11T00:00:00+00:00');

        Assert::true($certificate->isExpiringWithin(days: 0, now: $now));
    }

    public function throwsWhenIsExpiringWithinReceivesNegativeDays(): void
    {
        try {
            $this->certificate(validUntil: '2026-04-01T00:00:00+00:00')->isExpiringWithin(days: -1);
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Days must be greater than or equal to 0');
        }
    }

    public function usesCurrentTimeWhenNowOmitted(): void
    {
        $future = $this->certificate(validUntil: '2999-01-01T00:00:00+00:00');
        $past = $this->certificate(validUntil: '2000-01-01T00:00:00+00:00');

        Assert::false($future->isExpired());
        Assert::true($past->isExpired());
        Assert::true($future->daysUntilExpiry() > 0);
    }

    private function certificate(string $validUntil): SslCertificate
    {
        return new SslCertificate(
            validFrom: new DateTimeImmutable(datetime: '2000-01-01T00:00:00+00:00'),
            validUntil: new DateTimeImmutable(datetime: $validUntil),
            subjectCn: 'example.com',
        );
    }

    public function serializesDatesAsIso8601(): void
    {
        $certificate = new SslCertificate(
            validFrom: new DateTimeImmutable(datetime: '2026-01-01T00:00:00+00:00'),
            validUntil: new DateTimeImmutable(datetime: '2026-04-01T00:00:00+00:00'),
            subjectCn: 'example.com',
            issuer: 'Example CA',
        );

        Assert::same(
            $certificate->jsonSerialize(),
            [
                'validFrom' => '2026-01-01T00:00:00+00:00',
                'validUntil' => '2026-04-01T00:00:00+00:00',
                'subjectCn' => 'example.com',
                'issuer' => 'Example CA',
            ],
        );
    }
}
