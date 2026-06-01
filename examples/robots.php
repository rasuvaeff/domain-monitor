<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\DomainMonitor\RobotsTxtService;
use Symfony\Component\HttpClient\Psr18Client;

require dirname(__DIR__) . '/vendor/autoload.php';

$url = $argv[1] ?? 'https://example.com';

$client = new Psr18Client();
$requestFactory = new Psr17Factory();

$result = (new RobotsTxtService(httpClient: $client, requestFactory: $requestFactory))->check(baseUrl: $url);

var_dump($result);
