<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use Rasuvaeff\DomainMonitor\HttpProbeWithResponse;
use Rasuvaeff\DomainMonitor\ProbeResult;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeResponse;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(HttpProbeWithResponse::class)]
final class HttpProbeWithResponseTest
{
    public function exposesResultAndResponse(): void
    {
        $result = new ProbeResult(status: 200, totalTime: 0.1);
        $response = new FakeResponse(statusCode: 200);

        $dto = new HttpProbeWithResponse(result: $result, response: $response);

        Assert::same($dto->result, $result);
        Assert::same($dto->response, $response);
    }
}
