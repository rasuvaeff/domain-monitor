<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use Psr\Http\Message\ResponseInterface;

/**
 * @api
 */
interface HttpContentCheckServiceInterface
{
    public function check(
        string $url,
        int $expectedStatus = 200,
        ?string $requiredText = null,
        ?string $forbiddenText = null,
        ?HttpProbeOptions $options = null,
    ): HttpContentCheck;

    public function checkFromResponse(
        ResponseInterface $response,
        int $expectedStatus = 200,
        ?string $requiredText = null,
        ?string $forbiddenText = null,
    ): HttpContentCheck;
}
