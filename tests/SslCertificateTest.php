<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Rasuvaeff\DomainMonitor\SslCertificate;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
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

    #[Property(runs: 200)]
    public function isExpiredIsMonotonicByNow(int $validUntilDays, array $nowSpan): void
    {
        [$now1Days, $now2Days] = $nowSpan;

        $epoch = new DateTimeImmutable(datetime: '2020-01-01T00:00:00+00:00');
        $certificate = new SslCertificate(
            validFrom: $epoch,
            validUntil: $epoch->modify(modifier: "+{$validUntilDays} days"),
            subjectCn: 'example.com',
        );
        $now1 = $epoch->modify(modifier: "+{$now1Days} days");
        $now2 = $epoch->modify(modifier: "+{$now2Days} days");

        if ($certificate->isExpired(now: $now1)) {
            Assert::true($certificate->isExpired(now: $now2));
        }
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function isExpiredIsMonotonicByNowGenerators(): array
    {
        return [
            'validUntilDays' => Gen::intBetween(0, 365),
            'nowSpan' => Gen::intRange(min: 0, max: 365),
        ];
    }

    /**
     * @return iterable<string, array{int, array{int, int}}>
     */
    public static function isExpiredIsMonotonicByNowExamples(): iterable
    {
        // Expiry at the epoch itself: `<=` means already expired at the very
        // instant, and both readings land on the same boundary.
        yield 'expires at the epoch' => [0, [0, 0]];
        yield 'expires exactly at the second reading' => [1, [0, 1]];
        yield 'both readings after expiry' => [1, [2, 300]];
    }

    #[Property(runs: 300)]
    public function expiryWindowWidensMonotonicallyAndAlwaysCoversAnExpiredCertificate(
        DateTimeImmutable $validUntil,
        DateTimeImmutable $now,
        int $narrowDays,
        int $extraDays,
    ): void {
        $certificate = new SslCertificate(
            validFrom: $validUntil->modify(modifier: '-365 days'),
            validUntil: $validUntil,
            subjectCn: 'example.com',
        );

        $expired = $certificate->isExpired(now: $now);

        Classify::cover($expired, 'already expired', 20.0);
        Classify::cover(!$expired, 'still valid', 20.0);

        $narrow = $certificate->isExpiringWithin(days: $narrowDays, now: $now);
        $wide = $certificate->isExpiringWithin(days: $narrowDays + $extraDays, now: $now);

        // A wider window can only ever answer yes where a narrower one did.
        Assert::true(!$narrow || $wide);
        // And an already-expired certificate is inside every window, however
        // narrow — an alert keyed on "expiring within N days" that goes quiet
        // once the certificate is actually dead is the failure worth pinning.
        Assert::true(!$expired || $narrow);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function expiryWindowWidensMonotonicallyAndAlwaysCoversAnExpiredCertificateGenerators(): array
    {
        // Real dates rather than day offsets from a fixed epoch: an expiry
        // comparison is exactly the place a DST boundary or a timezone-shifted
        // timestamp turns "valid" into "expired".
        $from = new DateTimeImmutable(datetime: '2020-01-01T00:00:00+00:00');
        $to = new DateTimeImmutable(datetime: '2030-01-01T00:00:00+00:00');

        return [
            'validUntil' => Gen::datetime(min: $from, max: $to),
            'now' => Gen::datetime(min: $from, max: $to),
            'narrowDays' => Gen::intBetween(0, 90),
            'extraDays' => Gen::intBetween(0, 275),
        ];
    }

    /**
     * @return iterable<string, array{DateTimeImmutable, DateTimeImmutable, int, int}>
     */
    public static function expiryWindowWidensMonotonicallyAndAlwaysCoversAnExpiredCertificateExamples(): iterable
    {
        $moment = new DateTimeImmutable(datetime: '2026-06-01T12:00:00+00:00');

        yield 'expires this very second, zero-day window' => [$moment, $moment, 0, 0];
        yield 'expires a second from now' => [$moment->modify(modifier: '+1 second'), $moment, 0, 30];
        yield 'a year of headroom' => [$moment->modify(modifier: '+365 days'), $moment, 0, 90];
    }

    #[Property(runs: 200)]
    public function daysUntilExpiryStaysWithinCertificateLifetime(array $case): void
    {
        [$lifetime, $offset] = $case;

        $epoch = new DateTimeImmutable(datetime: '2020-01-01T00:00:00+00:00');
        $certificate = new SslCertificate(
            validFrom: $epoch,
            validUntil: $epoch->modify(modifier: "+{$lifetime} days"),
            subjectCn: 'example.com',
        );
        $now = $epoch->modify(modifier: "+{$offset} days");

        $days = $certificate->daysUntilExpiry(now: $now);

        Assert::true($days >= 0);
        Assert::true($days <= $lifetime);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function daysUntilExpiryStaysWithinCertificateLifetimeGenerators(): array
    {
        return [
            'case' => Gen::flatMap(
                Gen::intBetween(min: 1, max: 365),
                static fn(int $lifetime): ArbitraryInterface => Gen::tuple(
                    Gen::constant(value: $lifetime),
                    Gen::intBetween(min: 0, max: $lifetime),
                ),
            ),
        ];
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
