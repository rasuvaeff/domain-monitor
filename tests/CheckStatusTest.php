<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
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
        // A quarter of the pairs are a status against itself and assert
        // nothing; the gate is what keeps the other three quarters from
        // quietly disappearing behind a generator change.
        Classify::cover($a !== $b, 'distinct pair', 50.0);
        Classify::when($a === $b, 'same status');

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

    /**
     * @return iterable<string, array{CheckStatus, CheckStatus}>
     */
    public static function distinctStatusesHaveDistinctSeveritiesExamples(): iterable
    {
        // The pair the ordering exists for: an unevaluated check must not
        // outrank a healthy one.
        yield 'unknown against ok' => [CheckStatus::UNKNOWN, CheckStatus::OK];
        yield 'warning against critical' => [CheckStatus::WARNING, CheckStatus::CRITICAL];
        yield 'a status against itself' => [CheckStatus::OK, CheckStatus::OK];
    }

    #[Property(runs: 200)]
    public function worstBySeverityDoesNotDependOnOrder(array $statuses): void
    {
        $worst = static function (array $list): ?CheckStatus {
            $best = null;

            foreach ($list as $status) {
                if ($best === null || $status->severity() > $best->severity()) {
                    $best = $status;
                }
            }

            return $best;
        };

        Classify::cover($statuses === [], 'empty list', 5.0);
        Classify::cover($statuses !== [], 'non-empty list', 50.0);

        // Worst-wins is the reason severity() exists, and a fold that picks a
        // different winner depending on the order checks ran in would make a
        // report's aggregate status a coin flip.
        Assert::same($worst(\array_reverse($statuses)), $worst($statuses));
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function worstBySeverityDoesNotDependOnOrderGenerators(): array
    {
        return ['statuses' => Gen::arrayOf(Gen::enum(CheckStatus::class), maxSize: 8)];
    }

    /**
     * @return iterable<string, array{list<CheckStatus>}>
     */
    public static function worstBySeverityDoesNotDependOnOrderExamples(): iterable
    {
        yield 'nothing to aggregate' => [[]];
        yield 'unknown must not outrank ok' => [[CheckStatus::UNKNOWN, CheckStatus::OK]];
        yield 'critical behind two healthy checks' => [[CheckStatus::OK, CheckStatus::OK, CheckStatus::CRITICAL]];
    }
}
