<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\DomainMonitor\SecurityHeadersService;
use Symfony\Component\HttpClient\Psr18Client;

require dirname(__DIR__) . '/vendor/autoload.php';

$url = $argv[1] ?? 'https://example.com';

$client = new Psr18Client();
$requestFactory = new Psr17Factory();
$request = $requestFactory->createRequest(method: 'GET', uri: $url);
$response = $client->sendRequest(request: $request);

$result = (new SecurityHeadersService())->check(response: $response);

var_dump($result);
