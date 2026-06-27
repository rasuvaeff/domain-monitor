<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use InvalidArgumentException;
use Rasuvaeff\DomainMonitor\ProbeResult;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(ProbeResult::class)]
final class ProbeResultTest
{
    #[DataProvider('validStatusProvider')]
    public function acceptsValidStatuses(int $status): void
    {
        $result = new ProbeResult(status: $status, totalTime: 0.5);

        Assert::same($result->status, $status);
        Assert::same($result->totalTime, 0.5);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function validStatusProvider(): iterable
    {
        yield 'network failure' => [0];
        yield 'lower boundary' => [100];
        yield 'ok' => [200];
        yield 'upper boundary' => [599];
    }

    #[DataProvider('invalidStatusProvider')]
    public function throwsOnInvalidStatus(int $status): void
    {
        try {
            new ProbeResult(status: $status, totalTime: 0.1);
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains(\sprintf('Invalid HTTP status %d', $status));
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidStatusProvider(): iterable
    {
        yield 'below lower boundary' => [99];
        yield 'above upper boundary' => [600];
        yield 'negative' => [-1];
    }

    public function acceptsZeroTotalTime(): void
    {
        $result = new ProbeResult(status: 200, totalTime: 0.0);

        Assert::same($result->totalTime, 0.0);
    }

    public function throwsOnNegativeTotalTime(): void
    {
        try {
            new ProbeResult(status: 200, totalTime: -0.1);
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Total time must be greater than or equal to 0');
        }
    }
}
