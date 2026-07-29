<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\DomainMonitor\EmailSecurityService;

$host = $argv[1] ?? 'example.com';

// DKIM is selector-dependent. Pass selectors you expect the domain to publish,
// or omit the argument to skip DKIM entirely.
$service = new EmailSecurityService(dkimSelectors: ['default', 'google', 'selector1', 'selector2']);

$check = $service->check(host: $host);

echo "Host: {$host}\n";
echo "Status: {$check->status->value}\n\n";

echo "SPF:    " . ($check->hasSpf ? 'yes' : 'no') . "\n";
if ($check->spfRecord !== null) {
    echo "        {$check->spfRecord}\n";
}

echo "DMARC:  " . ($check->hasDmarc ? 'yes' : 'no') . "\n";
if ($check->dmarcPolicy !== null) {
    echo "        policy={$check->dmarcPolicy}\n";
}

echo "DKIM:   " . ($check->hasDkim ? 'yes' : 'no') . "\n";
if ($check->dkimSelectorsFound !== []) {
    echo "        selectors=" . implode(separator: ',', array: $check->dkimSelectorsFound) . "\n";
}

echo "CAA:    " . ($check->hasCaa ? 'yes' : 'no') . "\n";
foreach ($check->caaRecords as $issuer) {
    echo "        {$issuer}\n";
}

echo "MX:     " . count(value: $check->mxRecords) . " record(s)\n";
foreach ($check->mxRecords as $target) {
    echo "        {$target}\n";
}

if ($check->reason !== null) {
    echo "\n{$check->reason}\n";
}
