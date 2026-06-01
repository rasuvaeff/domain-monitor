<?php

declare(strict_types=1);

use Rasuvaeff\DomainMonitor\PortService;

require dirname(__DIR__) . '/vendor/autoload.php';

$host = $argv[1] ?? 'example.com';
$port = isset($argv[2]) ? (int) $argv[2] : 443;

$result = (new PortService())->check(host: $host, port: $port);

var_dump($result);
