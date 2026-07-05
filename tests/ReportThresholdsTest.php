<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use InvalidArgumentException;
use Rasuvaeff\DomainMonitor\ReportThresholds;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ReportThresholds::class)]
final class ReportThresholdsTest
{
    public function defaultDisablesSslWarningAndKeepsThirtyDayWhoisWindow(): void
    {
        $thresholds = ReportThresholds::default();

        Assert::null($thresholds->sslWarnDays);
        Assert::same($thresholds->whoisWarnDays, 30);
    }

    public function strictEnablesSslWarning(): void
    {
        $thresholds = ReportThresholds::strict();

        Assert::same($thresholds->sslWarnDays, 14);
        Assert::same($thresholds->whoisWarnDays, 30);
    }

    public function acceptsZeroBoundaries(): void
    {
        $thresholds = new ReportThresholds(sslWarnDays: 0, whoisWarnDays: 0);

        Assert::same($thresholds->sslWarnDays, 0);
        Assert::same($thresholds->whoisWarnDays, 0);
    }

    public function throwsOnNegativeSslWarnDays(): void
    {
        try {
            new ReportThresholds(sslWarnDays: -1);
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('sslWarnDays must be greater than or equal to 0');
        }
    }

    public function throwsOnNegativeWhoisWarnDays(): void
    {
        try {
            new ReportThresholds(whoisWarnDays: -1);
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('whoisWarnDays must be greater than or equal to 0');
        }
    }

    public function serializesToArray(): void
    {
        Assert::same(
            (new ReportThresholds(sslWarnDays: 14, whoisWarnDays: 45))->jsonSerialize(),
            ['sslWarnDays' => 14, 'whoisWarnDays' => 45],
        );
    }

    public function serializesNullSslWarnDays(): void
    {
        Assert::same(
            ReportThresholds::default()->jsonSerialize(),
            ['sslWarnDays' => null, 'whoisWarnDays' => 30],
        );
    }
}
