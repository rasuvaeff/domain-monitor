<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\DomainMonitor\CheckStatus;

#[CoversClass(CheckStatus::class)]
final class CheckStatusTest extends TestCase
{
    #[Test]
    public function hasExpectedValues(): void
    {
        $this->assertSame('ok', CheckStatus::OK->value);
        $this->assertSame('warning', CheckStatus::WARNING->value);
        $this->assertSame('critical', CheckStatus::CRITICAL->value);
        $this->assertSame('unknown', CheckStatus::UNKNOWN->value);
    }
}
