<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\DomainMonitor\ProbeResult;

#[CoversClass(ProbeResult::class)]
final class ProbeResultTest extends TestCase
{
    #[Test]
    #[DataProvider('validStatusProvider')]
    public function acceptsValidStatuses(int $status): void
    {
        $result = new ProbeResult(status: $status, totalTime: 0.5);

        $this->assertSame($status, $result->status);
        $this->assertSame(0.5, $result->totalTime);
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

    #[Test]
    #[DataProvider('invalidStatusProvider')]
    public function throwsOnInvalidStatus(int $status): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessage(message: \sprintf('Invalid HTTP status %d', $status));

        new ProbeResult(status: $status, totalTime: 0.1);
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

    #[Test]
    public function acceptsZeroTotalTime(): void
    {
        $result = new ProbeResult(status: 200, totalTime: 0.0);

        $this->assertSame(0.0, $result->totalTime);
    }

    #[Test]
    public function throwsOnNegativeTotalTime(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessage(message: 'Total time must be greater than or equal to 0');

        new ProbeResult(status: 200, totalTime: -0.1);
    }
}
