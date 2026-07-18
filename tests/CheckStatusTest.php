<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use Rasuvaeff\DomainMonitor\CheckStatus;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(CheckStatus::class)]
final class CheckStatusTest
{
    public function hasExpectedValues(): void
    {
        Assert::same(CheckStatus::OK->value, 'ok');
        Assert::same(CheckStatus::WARNING->value, 'warning');
        Assert::same(CheckStatus::CRITICAL->value, 'critical');
        Assert::same(CheckStatus::UNKNOWN->value, 'unknown');
    }

    public function ordersSeverityWorstWinsWithUnknownLowest(): void
    {
        Assert::same(CheckStatus::UNKNOWN->severity(), 0);
        Assert::same(CheckStatus::OK->severity(), 1);
        Assert::same(CheckStatus::WARNING->severity(), 2);
        Assert::same(CheckStatus::CRITICAL->severity(), 3);
    }
}
