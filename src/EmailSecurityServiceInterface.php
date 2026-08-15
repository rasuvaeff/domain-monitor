<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

/**
 * @api
 */
interface EmailSecurityServiceInterface
{
    public function check(string $host): EmailSecurityCheck;
}
