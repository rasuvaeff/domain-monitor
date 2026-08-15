<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

use Psr\Http\Message\ResponseInterface;

/**
 * @api
 */
interface SecurityHeadersServiceInterface
{
    public function check(ResponseInterface $response): SecurityHeadersCheck;
}
