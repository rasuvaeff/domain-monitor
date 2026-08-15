<?php

declare(strict_types=1);

use Rasuvaeff\DomainMonitor\DnsService;
use Rasuvaeff\DomainMonitor\DomainMonitor;
use Rasuvaeff\DomainMonitor\DomainMonitorOptions;
use Rasuvaeff\DomainMonitor\PortService;
use Rasuvaeff\Retry\Retry;

require dirname(__DIR__) . '/vendor/autoload.php';

$host = $argv[1] ?? 'example.com';

$monitor = new DomainMonitor(
    dns: new DnsService(),
    port: new PortService(),
);

$report = $monitor->check(
    host: $host,
    options: new DomainMonitorOptions(
        retry: Retry::exponential(maxAttempts: 3, baseMs: 200),
    ),
);

echo "Aggregate: {$report->getStatus()->value}\n";

foreach ($report->getChecks() as $result) {
    echo sprintf("%-10s %-8s %s\n", $result->check->value, $result->status->value, $result->reason);
}

if ($report->hasErrors()) {
    echo "Errors:\n";

    foreach ($report->getErrors() as $error) {
        echo "  {$error->check->value}: {$error->message}\n";
    }
}
