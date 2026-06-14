<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\DomainMonitor\HttpProbeWithResponse;
use Rasuvaeff\DomainMonitor\ProbeResult;
use Rasuvaeff\DomainMonitor\Tests\Fixtures\FakeResponse;

#[CoversClass(HttpProbeWithResponse::class)]
final class HttpProbeWithResponseTest extends TestCase
{
    #[Test]
    public function exposesResultAndResponse(): void
    {
        $result = new ProbeResult(status: 200, totalTime: 0.1);
        $response = new FakeResponse(statusCode: 200);

        $dto = new HttpProbeWithResponse(result: $result, response: $response);

        $this->assertSame($result, $dto->result);
        $this->assertSame($response, $dto->response);
    }
}
