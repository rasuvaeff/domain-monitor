<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\DomainMonitor\TlsCipherService;

$host = $argv[1] ?? 'example.com';
$port = (int) ($argv[2] ?? 443);

$check = (new TlsCipherService())->check(host: $host, port: $port);

echo "Host: {$host}:{$port}\n";
echo "Status: {$check->status->value}\n\n";

if ($check->tlsVersion !== null) {
    echo "Protocol: {$check->tlsVersion}\n";
}

if ($check->cipherName !== null) {
    echo "Cipher:   {$check->cipherName}\n";
}

if ($check->cipherVersion !== null) {
    echo "Version:  {$check->cipherVersion}\n";
}

if ($check->usesWeakCipher) {
    echo "\nWeak cipher patterns matched: " . implode(separator: ', ', array: $check->weakCipherNames) . "\n";
}

if ($check->reason !== null) {
    echo "\n{$check->reason}\n";
}
