<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\DomainMonitor\HttpContentCheckService;
use Rasuvaeff\DomainMonitor\HttpProbeOptions;
use Rasuvaeff\DomainMonitor\HttpProbeService;
use Symfony\Component\HttpClient\Psr18Client;

require dirname(__DIR__) . '/vendor/autoload.php';

$client = new Psr18Client();
$requestFactory = new Psr17Factory();

$probe = (new HttpProbeService(httpClient: $client, requestFactory: $requestFactory))->check(
    url: 'https://example.com',
    options: new HttpProbeOptions(headers: ['Accept' => 'text/html']),
);
$content = (new HttpContentCheckService(httpClient: $client, requestFactory: $requestFactory))->check(
    url: 'https://example.com',
    requiredText: 'Example Domain',
);

var_dump($probe, $content);
