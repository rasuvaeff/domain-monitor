<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\DomainMonitor\CookieSecurityService;
use Symfony\Component\HttpClient\Psr18Client;

$url = $argv[1] ?? 'https://example.com';

$psr18 = new Psr18Client();
$psr17 = new Psr17Factory();
$request = $psr17->createRequest(method: 'GET', uri: $url);
$response = $psr18->sendRequest(request: $request);

$check = (new CookieSecurityService())->check(response: $response);

echo "URL: {$url}\n";
echo "Status: {$check->status->value}\n\n";

if ($check->cookies === []) {
    echo "No Set-Cookie headers.\n";

    return;
}

foreach ($check->cookies as $cookie) {
    $secure = $cookie['secure'] ? 'Secure' : 'no-Secure';
    $httpOnly = $cookie['httpOnly'] ? 'HttpOnly' : 'no-HttpOnly';
    $sameSite = $cookie['sameSite'] !== null ? "SameSite={$cookie['sameSite']}" : 'no-SameSite';

    echo "{$cookie['name']}: {$secure} · {$httpOnly} · {$sameSite}\n";
}

if ($check->insecureCookieNames !== []) {
    echo "\nInsecure cookies: " . implode(separator: ', ', array: $check->insecureCookieNames) . "\n";
}
