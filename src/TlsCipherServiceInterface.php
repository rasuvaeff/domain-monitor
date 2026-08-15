<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

/**
 * @api
 */
interface TlsCipherServiceInterface
{
    public function check(string $host, int $port = 443, float $timeoutSeconds = 10.0): TlsCipherCheck;
}
