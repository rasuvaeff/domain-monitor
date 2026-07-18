# Examples

| Script | Shows | Needs server? |
|---|---|---|
| `full-check.php` | Full domain check via `DomainMonitor` orchestrator | Yes |
| `http-probe.php` | PSR-18-based probe + content checks | Yes |
| `ssl-whois-dns.php` | SSL, WHOIS, and DNS checks | Yes |
| `port.php` | TCP port availability check | Yes |
| `security-headers.php` | Security headers (HSTS, CSP, etc.) analysis | Yes |
| `robots.php` | `/robots.txt` availability + sitemap extraction | Yes |
| `sitemap.php` | Sitemap availability + URL count | Yes |
| `report.php` | Building a `DomainHealthReport` from DTOs | No |
| `report-diff.php` | Diffing two reports into per-check status transitions | No |

## Running

HTTP-based examples require you to install and wire any PSR-18 client and PSR-17
request factory in your application, for example `symfony/http-client` and
`nyholm/psr7`.

```bash
composer require symfony/http-client nyholm/psr7
php examples/full-check.php example.com
php examples/http-probe.php
php examples/robots.php https://example.com
php examples/sitemap.php https://www.example.com/sitemap.xml
php examples/security-headers.php https://example.com
php examples/port.php example.com 443
```

Report and diff examples build composite DTOs — no network needed:

```bash
php examples/report.php
php examples/report-diff.php
```
