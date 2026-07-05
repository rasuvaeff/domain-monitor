<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use InvalidArgumentException;
use Rasuvaeff\DomainMonitor\CheckStatus;
use Rasuvaeff\DomainMonitor\HttpContentCheck;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(HttpContentCheck::class)]
final class HttpContentCheckTest
{
    public function preservesFields(): void
    {
        $check = new HttpContentCheck(
            status: CheckStatus::OK,
            httpStatus: 200,
            finalUrl: 'https://example.com',
            requiredTextFound: true,
            forbiddenTextFound: false,
        );

        Assert::same($check->status, CheckStatus::OK);
        Assert::same($check->httpStatus, 200);
        Assert::same($check->finalUrl, 'https://example.com');
        Assert::true($check->requiredTextFound);
        Assert::false($check->forbiddenTextFound);
    }

    public function allowsNetworkFailureStatus(): void
    {
        $check = new HttpContentCheck(
            status: CheckStatus::UNKNOWN,
            httpStatus: 0,
            finalUrl: null,
            requiredTextFound: false,
            forbiddenTextFound: false,
        );

        Assert::same($check->httpStatus, 0);
        Assert::null($check->finalUrl);
    }

    #[DataProvider('throwsOnInvalidStatusProvider')]
    public function throwsOnInvalidStatus(int $httpStatus): void
    {
        Expect::exception(InvalidArgumentException::class);

        new HttpContentCheck(
            status: CheckStatus::UNKNOWN,
            httpStatus: $httpStatus,
            finalUrl: null,
            requiredTextFound: false,
            forbiddenTextFound: false,
        );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function throwsOnInvalidStatusProvider(): iterable
    {
        yield '99' => [99];
        yield '600' => [600];
    }

    public function acceptsBoundaryHttpStatus100(): void
    {
        $check = new HttpContentCheck(
            status: CheckStatus::OK,
            httpStatus: 100,
            finalUrl: null,
            requiredTextFound: false,
            forbiddenTextFound: false,
        );

        Assert::same($check->httpStatus, 100);
    }

    public function acceptsBoundaryHttpStatus599(): void
    {
        $check = new HttpContentCheck(
            status: CheckStatus::OK,
            httpStatus: 599,
            finalUrl: null,
            requiredTextFound: false,
            forbiddenTextFound: false,
        );

        Assert::same($check->httpStatus, 599);
    }

    public function serializesStatusEnumToItsValue(): void
    {
        $check = new HttpContentCheck(
            status: CheckStatus::CRITICAL,
            httpStatus: 500,
            finalUrl: 'https://example.com/final',
            requiredTextFound: false,
            forbiddenTextFound: true,
        );

        Assert::same(
            $check->jsonSerialize(),
            [
                'status' => 'critical',
                'httpStatus' => 500,
                'finalUrl' => 'https://example.com/final',
                'requiredTextFound' => false,
                'forbiddenTextFound' => true,
            ],
        );
    }
}
