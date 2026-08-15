<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

/**
 * @api
 */
interface WhoisServiceInterface
{
    public function check(string $host): ?TldInfo;
}
