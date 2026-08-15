<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

/**
 * @api
 */
interface DnsServiceInterface
{
    public function check(string $host): DnsRecords;
}
