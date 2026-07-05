<?php

declare(strict_types=1);

namespace Rasuvaeff\DomainMonitor;

/**
 * @api
 */
enum CheckName: string
{
    case Probe = 'probe';
    case Ssl = 'ssl';
    case Whois = 'whois';
    case Dns = 'dns';
    case Content = 'content';
    case Port = 'port';
    case SecurityHeaders = 'security-headers';
    case RobotsTxt = 'robots-txt';
    case Sitemap = 'sitemap';
}
