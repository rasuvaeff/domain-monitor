<?php

declare(strict_types=1);

use Iodev\Whois\Factory;
use Rasuvaeff\DomainMonitor\DnsService;
use Rasuvaeff\DomainMonitor\SslCertificateService;
use Rasuvaeff\DomainMonitor\WhoisService;

require dirname(__DIR__) . '/vendor/autoload.php';

$ssl = (new SslCertificateService())->check(host: 'example.com');
$dns = (new DnsService())->check(host: 'example.com');
$whois = (new WhoisService(whois: Factory::get()->createWhois()))->check(host: 'example.com');

var_dump($ssl, $dns, $whois);
