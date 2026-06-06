<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\SitemapCheck;

#[CoversClass(SitemapCheck::class)]
final class SitemapCheckTest extends TestCase
{
    #[Test]
    public function preservesFields(): void
    {
        $check = new SitemapCheck(status: CheckStatus::OK, httpStatus: 200, exists: true, urlCount: 42);

        $this->assertSame(CheckStatus::OK, $check->status);
        $this->assertSame(200, $check->httpStatus);
        $this->assertTrue($check->exists);
        $this->assertSame(42, $check->urlCount);
    }

    #[Test]
    public function throwsOnInvalidStatus(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessage(message: 'Invalid HTTP status 700');

        new SitemapCheck(status: CheckStatus::UNKNOWN, httpStatus: 700, exists: false, urlCount: 0);
    }

    #[Test]
    public function acceptsHttpStatusZero(): void
    {
        $check = new SitemapCheck(status: CheckStatus::UNKNOWN, httpStatus: 0, exists: false, urlCount: 0);

        $this->assertSame(0, $check->httpStatus);
    }

    #[Test]
    public function acceptsBoundaryHttpStatus100(): void
    {
        $check = new SitemapCheck(status: CheckStatus::OK, httpStatus: 100, exists: false, urlCount: 0);

        $this->assertSame(100, $check->httpStatus);
    }

    #[Test]
    public function acceptsBoundaryHttpStatus599(): void
    {
        $check = new SitemapCheck(status: CheckStatus::OK, httpStatus: 599, exists: false, urlCount: 0);

        $this->assertSame(599, $check->httpStatus);
    }

    #[Test]
    public function acceptsZeroUrlCount(): void
    {
        $check = new SitemapCheck(status: CheckStatus::OK, httpStatus: 200, exists: false, urlCount: 0);

        $this->assertSame(0, $check->urlCount);
    }

    #[Test]
    public function throwsOnNegativeUrlCount(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessage(message: 'URL count must be greater than or equal to 0');

        new SitemapCheck(status: CheckStatus::UNKNOWN, httpStatus: 200, exists: true, urlCount: -1);
    }
}
