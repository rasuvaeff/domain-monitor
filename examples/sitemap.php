<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\DomainMonitor\SitemapService;
use Symfony\Component\HttpClient\Psr18Client;

require dirname(__DIR__) . '/vendor/autoload.php';

$url = $argv[1] ?? 'https://www.example.com/sitemap.xml';

$client = new Psr18Client();
$requestFactory = new Psr17Factory();

$result = (new SitemapService(httpClient: $client, requestFactory: $requestFactory))->check(sitemapUrl: $url);

var_dump($result);
