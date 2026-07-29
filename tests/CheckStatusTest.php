<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
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

    #[Property(runs: 100)]
    public function distinctStatusesHaveDistinctSeverities(CheckStatus $a, CheckStatus $b): void
    {
        if ($a !== $b) {
            Assert::true($a->severity() !== $b->severity());
        }
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function distinctStatusesHaveDistinctSeveritiesGenerators(): array
    {
        return [
            'a' => Gen::enum(CheckStatus::class),
            'b' => Gen::enum(CheckStatus::class),
        ];
    }
}
