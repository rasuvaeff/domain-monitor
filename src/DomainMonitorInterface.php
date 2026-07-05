<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

/**
 * @api
 */
interface DomainMonitorInterface
{
    public function check(string $host, ?DomainMonitorOptions $options = null): DomainHealthReport;
}
