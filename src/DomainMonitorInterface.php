<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

/**
 * @api
 */
interface DomainMonitorInterface
{
    public function check(string $host, ?DomainMonitorOptions $options = null): DomainHealthReport;

    /**
     * @param list<string> $hosts
     *
     * @return array<string, DomainHealthReport> keyed by normalized host
     */
    public function checkMany(array $hosts, ?DomainMonitorOptions $options = null): array;
}
