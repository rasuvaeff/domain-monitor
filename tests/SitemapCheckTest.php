<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use InvalidArgumentException;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\SitemapCheck;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(SitemapCheck::class)]
final class SitemapCheckTest
{
    public function preservesFields(): void
    {
        $check = new SitemapCheck(status: CheckStatus::OK, httpStatus: 200, exists: true, urlCount: 42);

        Assert::same($check->status, CheckStatus::OK);
        Assert::same($check->httpStatus, 200);
        Assert::true($check->exists);
        Assert::same($check->urlCount, 42);
    }

    public function throwsOnInvalidStatus(): void
    {
        try {
            new SitemapCheck(status: CheckStatus::UNKNOWN, httpStatus: 700, exists: false, urlCount: 0);
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Invalid HTTP status 700');
        }
    }

    public function acceptsHttpStatusZero(): void
    {
        $check = new SitemapCheck(status: CheckStatus::UNKNOWN, httpStatus: 0, exists: false, urlCount: 0);

        Assert::same($check->httpStatus, 0);
    }

    public function acceptsBoundaryHttpStatus100(): void
    {
        $check = new SitemapCheck(status: CheckStatus::OK, httpStatus: 100, exists: false, urlCount: 0);

        Assert::same($check->httpStatus, 100);
    }

    public function acceptsBoundaryHttpStatus599(): void
    {
        $check = new SitemapCheck(status: CheckStatus::OK, httpStatus: 599, exists: false, urlCount: 0);

        Assert::same($check->httpStatus, 599);
    }

    public function acceptsZeroUrlCount(): void
    {
        $check = new SitemapCheck(status: CheckStatus::OK, httpStatus: 200, exists: false, urlCount: 0);

        Assert::same($check->urlCount, 0);
    }

    public function throwsOnNegativeUrlCount(): void
    {
        try {
            new SitemapCheck(status: CheckStatus::UNKNOWN, httpStatus: 200, exists: true, urlCount: -1);
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('URL count must be greater than or equal to 0');
        }
    }

    public function serializesStatusEnumAndCounts(): void
    {
        $check = new SitemapCheck(status: CheckStatus::OK, httpStatus: 200, exists: true, urlCount: 42);

        Assert::same(
            $check->jsonSerialize(),
            [
                'status' => 'ok',
                'httpStatus' => 200,
                'exists' => true,
                'urlCount' => 42,
            ],
        );
    }
}
