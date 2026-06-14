<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use Psr\Http\Message\ResponseInterface;

/**
 * @api
 */
final readonly class HttpProbeWithResponse
{
    public function __construct(
        public ProbeResult $result,
        public ResponseInterface $response,
    ) {}
}
