<?php

declare(strict_types=1);

use Rasuvaeff\DomainMonitor\DomainMonitor;
use Rasuvaeff\DomainMonitor\DomainMonitorOptions;
use Rasuvaeff\DomainMonitor\DnsService;
use Rasuvaeff\DomainMonitor\PortService;
use Rasuvaeff\Duration\Duration;

require dirname(__DIR__) . '/vendor/autoload.php';

$hosts = \array_slice($argv, 1) ?: ['example.com', 'example.org'];

$monitor = new DomainMonitor(
    dns: new DnsService(),
    port: new PortService(),
);

$reports = $monitor->checkMany(
    hosts: $hosts,
    options: new DomainMonitorOptions(maxDuration: Duration::seconds(5)),
);

foreach ($reports as $host => $report) {
    echo "{$host}: {$report->getStatus()->value}\n";

    foreach ($report->getChecks() as $result) {
        echo \sprintf("  %-16s %-8s %s\n", $result->check->value, $result->status->value, $result->reason);
    }
}
