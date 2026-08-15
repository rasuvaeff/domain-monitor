<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

/**
 * @api
 */
interface RobotsTxtServiceInterface
{
    public function check(string $baseUrl, ?HttpProbeOptions $options = null): RobotsTxtCheck;
}
