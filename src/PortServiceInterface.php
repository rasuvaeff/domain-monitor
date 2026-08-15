<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

/**
 * @api
 */
interface PortServiceInterface
{
    public function check(string $host, int $port, float $timeoutSeconds = 5.0): PortCheck;
}
