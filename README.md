# rasuvaeff/domain-monitor

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/domain-monitor/v)](https://packagist.org/packages/rasuvaeff/domain-monitor)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/domain-monitor/downloads)](https://packagist.org/packages/rasuvaeff/domain-monitor)
[![Build](https://github.com/rasuvaeff/domain-monitor/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/domain-monitor/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/domain-monitor/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/domain-monitor/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/domain-monitor/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/domain-monitor/php)](https://packagist.org/packages/rasuvaeff/domain-monitor)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)
[Русская версия](README.ru.md)

A modular domain monitoring toolkit for PHP 8.3+. Zero-framework, PSR-compatible, with small immutable DTOs and focused stateless services. Each checker does one thing — you compose them as needed.

**Checks:** HTTP probing · SSL certificates · WHOIS · DNS · TCP ports · security headers · `robots.txt` · sitemaps.

**Does not include:** scheduling, persistence, caching, or async runners. The package provides building blocks and a `DomainMonitor` orchestrator; your application provides the workflow.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference.

## Requirements

- PHP 8.3+
- `ext-openssl`, `ext-simplexml`
- A PSR-18 client and PSR-17 request factory for HTTP-based checks
- `io-developer/php-whois` (pulls `ext-curl`, `ext-mbstring`)
- `ext-intl` is optional (IDN normalization only)
- `ext-sockets` is optional (DNS resolution only)

## Installation

```bash
composer require rasuvaeff/domain-monitor
```

For HTTP checks you'll also need a PSR-18/PSR-17 implementation:

```bash
composer require symfony/http-client nyholm/psr7
```

## Quick start: a full domain check

### Simplest: the factory

`DomainMonitor::create()` wires every check from a single PSR-18 client + PSR-17 factory (WHOIS optional):

```php
use Iodev\Whois\Factory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\DomainMonitor\DomainMonitor;
use Symfony\Component\HttpClient\Psr18Client;

$monitor = DomainMonitor::create(
    httpClient: new Psr18Client(),
    requestFactory: new Psr17Factory(),
    whois: Factory::get()->createWhois(), // omit to disable the WHOIS check
);

$report = $monitor->check(host: 'example.com');

echo $report->getStatus()->value; // 'ok' | 'warning' | 'critical' | 'unknown'
```

For granular control over which checks run, use `DomainMonitorBuilder`:

```php
use Rasuvaeff\DomainMonitor\DomainMonitorBuilder;

$monitor = DomainMonitorBuilder::create()
    ->withHttp(client: new Psr18Client(), requestFactory: new Psr17Factory())
    ->withWhois(Factory::get()->createWhois())
    ->withoutPort()
    ->build();
```

### Using the orchestrator (recommended)

```php
use Iodev\Whois\Factory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\DomainMonitor\{
    DnsService,
    DomainMonitor,
    DomainMonitorOptions,
    HttpContentCheckService,
    HttpProbeService,
    PortService,
    RobotsTxtService,
    SecurityHeadersService,
    SitemapService,
    SslCertificateService,
    WhoisService,
};
use Symfony\Component\HttpClient\Psr18Client;

$client = new Psr18Client();
$requestFactory = new Psr17Factory();

$monitor = new DomainMonitor(
    httpProbe: new HttpProbeService(httpClient: $client, requestFactory: $requestFactory),
    ssl: new SslCertificateService(),
    whois: new WhoisService(whois: Factory::get()->createWhois()),
    dns: new DnsService(),
    port: new PortService(),
    securityHeaders: new SecurityHeadersService(),
    robotsTxt: new RobotsTxtService(httpClient: $client, requestFactory: $requestFactory),
    sitemap: new SitemapService(httpClient: $client, requestFactory: $requestFactory),
    content: new HttpContentCheckService(httpClient: $client, requestFactory: $requestFactory),
);

$report = $monitor->check(
    host: 'example.com',
    options: new DomainMonitorOptions(timeoutSeconds: 10.0),
);

echo $report->getStatus()->value; // 'ok' | 'warning' | 'critical' | 'unknown'
```

Services are optional — pass `null` (or omit) to disable a check. The orchestrator reuses a single HTTP response for probe + security headers + content check. Failed checks are caught, logged via PSR-3, and omitted from the report.

### Manual composition

```php
use DateTimeImmutable;
use Iodev\Whois\Factory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\DomainMonitor\{
    DnsService,
    DomainHealthReport,
    HttpContentCheckService,
    HttpProbeService,
    PortService,
    RobotsTxtService,
    SecurityHeadersService,
    SitemapService,
    SslCertificateService,
    WhoisService,
};
use Symfony\Component\HttpClient\Psr18Client;

$host = 'example.com';
$client = new Psr18Client();
$requestFactory = new Psr17Factory();

$report = new DomainHealthReport(
    host: $host,
    probe: (new HttpProbeService(httpClient: $client, requestFactory: $requestFactory))
        ->check(url: "https://{$host}"),
    ssl: (new SslCertificateService())->check(host: $host),
    whois: (new WhoisService(whois: Factory::get()->createWhois()))->check(host: $host),
    dns: (new DnsService())->check(host: $host),
    port: (new PortService())->check(host: $host, port: 443),
);

// Aggregate status: worst among checks (OK → WARNING → CRITICAL → UNKNOWN)
echo $report->getStatus()->value;
```

## Reading the report

`getStatus()` is the aggregate (worst of all checks). For the *why*, iterate per-check results — each carries a `CheckName`, a `CheckStatus`, and a human-readable `reason`:

```php
foreach ($report->getChecks() as $result) {
    printf("%-16s %-8s %s\n", $result->check->value, $result->status->value, $result->reason);
}
// probe            ok       HTTP 200
// ssl              ok       Certificate valid, expires in 61 day(s)
// whois            warning  Domain expires in 12 day(s)

$ssl = $report->getCheck(name: CheckName::Ssl); // ?CheckResult
```

### Errors vs disabled checks

A check that was **not configured** is `null`. A check that **ran but threw** is recorded separately — it appears in `getChecks()` as `UNKNOWN` (never inflating the aggregate) and in `getErrors()`:

```php
if ($report->hasErrors()) {
    foreach ($report->getErrors() as $error) {
        // CheckError { check: CheckName, message: string }
        echo "{$error->check->value}: {$error->message}\n";
    }
}
```

Treat `getStatus() === CheckStatus::OK` together with `hasErrors() === true` as "OK but incomplete".

### Thresholds

By default SSL is `CRITICAL` only once expired, and WHOIS warns within 30 days. Opt in to "SSL expiring soon = warning" (and tune the WHOIS window) with `ReportThresholds`:

```php
use Rasuvaeff\DomainMonitor\DomainMonitorOptions;
use Rasuvaeff\DomainMonitor\ReportThresholds;

$report = $monitor->check(
    host: 'example.com',
    options: new DomainMonitorOptions(
        thresholds: ReportThresholds::strict(), // SSL warns 14 days before expiry
        // or: new ReportThresholds(sslWarnDays: 30, whoisWarnDays: 45)
    ),
);
```

`ReportThresholds::default()` reproduces pre-1.2.0 behaviour exactly.

### Serialization

Every result DTO implements `JsonSerializable`, so the whole report encodes in one call — dates as ISO-8601, enums as their values, disabled checks as `null`:

```php
$json = json_encode($report, JSON_THROW_ON_ERROR);
```

The `checks` array is the evaluated snapshot (frozen `reason` strings); nested raw DTOs (`ssl.validUntil`, `whois.expirationDate`) stay absolute so a stored blob is a faithful record.

## Detecting status changes

A `DomainHealthReport` is a single snapshot. To alert only when something *changes*, keep the previous snapshot (your application owns storage) and diff it against the current one with `ReportComparator` — a stateless helper that returns one `StatusTransition` per changed check:

```php
use Rasuvaeff\DomainMonitor\ReportComparator;
use Rasuvaeff\DomainMonitor\TransitionKind;

$comparator = new ReportComparator();

$previous = $storage->latest(host: 'example.com'); // your storage, may be null on first run
$current = $monitor->check(host: 'example.com');

$diff = $comparator->compare(previous: $previous ?? $current, current: $current);

if ($diff->hasChanges()) {
    foreach ($diff->getTransitions() as $t) {
        // $t->check, $t->from (?CheckStatus), $t->to (?CheckStatus), $t->kind
        printf("%s: %s -> %s (%s)\n", $t->check->value, $t->from?->value ?? '—', $t->to?->value ?? '—', $t->kind->value);
    }
}

$storage->save(host: 'example.com', report: $current); // for the next run
```

`compare()` returns a `ReportDiff` wrapper (`hasChanges()`, `getTransitions()`, `worstTransition()`); `diff()` returns the raw `list<StatusTransition>`. Each transition carries a `TransitionKind`:

| Kind | Meaning |
|---|---|
| `Appeared` | Check absent before, present now (`from` is `null`) |
| `Disappeared` | Check present before, absent now (`to` is `null`) |
| `Degraded` | Status got worse (e.g. `ok → critical`) |
| `Recovered` | Status got better (e.g. `critical → ok`) |
| `Changed` | Status changed to or from `UNKNOWN`, where severity is not comparable |

`ReportComparator` compares by `CheckName` and is deterministic (`diff($r, $r)` is always `[]`). It has no notion of scheduling, storage, or delivery — feeding transitions to webhooks or a realtime channel is the application's job. A ready-made pipeline (scheduling, history, webhook + Centrifugo alerts, a status page) lives in `rasuvaeff/monitor-dashboard`.

## Services

### HTTP probing

```php
use Rasuvaeff\DomainMonitor\HttpProbeOptions;
use Rasuvaeff\DomainMonitor\HttpProbeService;

$probe = (new HttpProbeService(httpClient: $client, requestFactory: $requestFactory))
    ->check(
        url: 'https://example.com',
        options: new HttpProbeOptions(method: 'HEAD', timeoutSeconds: 10.0),
    );

// ProbeResult { status: 200, totalTime: 0.12 }
var_dump($probe->status, $probe->totalTime);
```

`timeoutSeconds` is **best-effort only** — PSR-18 has no standard timeout API. Clients like Symfony's honor it; clients like raw Guzzle may not.

### SSL

```php
use Rasuvaeff\DomainMonitor\SslCertificateService;

$cert = (new SslCertificateService())->check(
    host: 'example.com',
    expectedOrg: 'Example Inc.', // optional org filter
);

if ($cert !== null) {
    echo $cert->daysUntilExpiry();      // e.g. 45
    echo $cert->isExpiringWithin(days: 30); // false
    echo $cert->subjectCn;              // "example.com"
    echo $cert->issuer;                 // "Example CA"
}
```

Note: SSL check reads the peer certificate **without trust chain verification** — it's a monitoring tool, not a PKI validator.

### WHOIS

```php
use Iodev\Whois\Factory;
use Rasuvaeff\DomainMonitor\WhoisService;

$info = (new WhoisService(whois: Factory::get()->createWhois()))
    ->check(host: 'example.com');

// TldInfo { domain, ?registrar, ?expirationDate, states }
echo $info->daysUntilExpiry(); // null if expirationDate missing
```

Fallback: if `www.example.com` fails, the service retries with `example.com` automatically.

### DNS

```php
use Rasuvaeff\DomainMonitor\DnsService;

$records = (new DnsService())->check(host: 'example.com');

// DnsRecords { a: ['93.184.216.34'], mx: ['...'], ns: ['...'], ... }
var_dump($records->a, $records->mx);
```

### Port check (TCP)

```php
use Rasuvaeff\DomainMonitor\PortService;

$check = (new PortService())->check(host: 'example.com', port: 443, timeoutSeconds: 5.0);
// PortCheck { status: OK, connectTime: 0.04, error: null }
```

### Security headers

```php
use Rasuvaeff\DomainMonitor\SecurityHeadersService;

// Pass a PSR-7 ResponseInterface (from a prior HTTP probe)
$headers = (new SecurityHeadersService())->check(response: $response);
// SecurityHeadersCheck { hasHsts: true, hasContentSecurityPolicy: false, ... }
```

### robots.txt

```php
use Rasuvaeff\DomainMonitor\RobotsTxtService;

$robots = (new RobotsTxtService(httpClient: $client, requestFactory: $requestFactory))
    ->check(baseUrl: 'https://example.com');
// RobotsTxtCheck { exists: true, sitemaps: ['https://example.com/sitemap.xml'] }
```

### Sitemap

```php
use Rasuvaeff\DomainMonitor\SitemapService;

$sitemap = (new SitemapService(httpClient: $client, requestFactory: $requestFactory))
    ->check(sitemapUrl: 'https://example.com/sitemap.xml');
// SitemapCheck { exists: true, urlCount: 42 }
```

### Content check

```php
use Rasuvaeff\DomainMonitor\HttpContentCheckService;

$content = (new HttpContentCheckService(httpClient: $client, requestFactory: $requestFactory))
    ->check(
        url: 'https://example.com',
        expectedStatus: 200,
        requiredText: 'Example Domain',     // must be present
        forbiddenText: 'Internal Error',    // must NOT be present
    );
// HttpContentCheck { status: OK, requiredTextFound: true, forbiddenTextFound: false }
```

### Build a report

```php
use Rasuvaeff\DomainMonitor\{DomainHealthReport, CheckStatus};
use Rasuvaeff\DomainMonitor\ProbeResult;
use Rasuvaeff\DomainMonitor\SslCertificate;

$report = new DomainHealthReport(
    host: 'example.com',
    probe: new ProbeResult(status: 200, totalTime: 0.13),
    ssl: new SslCertificate(/* ... */),
    whois: $tldInfo,
    dns: $dnsRecords,
);
echo $report->getStatus()->value; // 'ok' | 'warning' | 'critical' | 'unknown'
```

## Full API reference

| Class | What it does |
|---|---|
| `DomainMonitor` | Orchestrator: runs all configured services, reuses HTTP response for probe + security headers + content → `DomainHealthReport`; `create()` factory + implements `DomainMonitorInterface` |
| `DomainMonitorInterface` | Contract for `DomainMonitor` — mock/decorate it |
| `DomainMonitorBuilder` | Fluent, granular composition of the orchestrator (`withHttp`, `withWhois`, `withoutPort`, …) |
| `DomainMonitorOptions` | VO for orchestrator: port, timeout, method, userAgent, expectedOrg, expectedStatus, requiredText, forbiddenText, thresholds |
| `ReportThresholds` | VO: SSL expiry-warning window (`sslWarnDays`) + WHOIS warning window (`whoisWarnDays`); `default()` / `strict()` |
| `HostNormalizer` | Normalize hosts/URLs (lowercase, strip scheme/port/path, optional IDN) |
| `HttpProbeService` | PSR-18 GET/HEAD probe with measured time → `ProbeResult`; `probeWithResponse()` for response reuse |
| `HttpProbeWithResponse` | DTO: `ProbeResult` + `ResponseInterface` (for response reuse) |
| `HttpProbeOptions` | Configure method, headers, timeout, user-agent for HTTP probes |
| `ProbeResult` | DTO: `status`, `totalTime` |
| `SslCertificateService` | Read remote SSL cert; optional org filter → `SslCertificate` |
| `SslCertificate` | DTO: `validFrom`, `validUntil`, `subjectCn`, `issuer` + expiry helpers |
| `WhoisService` | Load & map WHOIS vendor data → `TldInfo` |
| `TldInfo` | DTO: `domain`, `?registrar`, `?expirationDate`, `states` |
| `DnsService` | `dns_get_record()` wrapper → `DnsRecords` |
| `DnsRecords` | DTO: `a`, `aaaa`, `mx`, `ns`, `txt`, `cname` |
| `PortService` | TCP reachability via `stream_socket_client()` → `PortCheck` |
| `PortCheck` | DTO: `status`, `host`, `port`, `connectTime`, `?error` |
| `SecurityHeadersService` | Check HSTS/CSP/XFO/XCTO on a PSR-7 response → `SecurityHeadersCheck` |
| `SecurityHeadersCheck` | DTO: flags for each header + present/missing lists |
| `RobotsTxtService` | Fetch `/robots.txt` + extract Sitemap hints → `RobotsTxtCheck` |
| `RobotsTxtCheck` | DTO: `exists`, `httpStatus`, `sitemaps[]` |
| `SitemapService` | Fetch sitemap + count `<url>` entries → `SitemapCheck` |
| `SitemapCheck` | DTO: `exists`, `httpStatus`, `urlCount` |
| `HttpContentCheckService` | Status code + required/forbidden keyword check → `HttpContentCheck`; `checkFromResponse()` for response reuse |
| `HttpContentCheck` | DTO: `status`, `httpStatus`, `?finalUrl`, text flags |
| `DomainHealthReport` | Composite DTO for all check results; `getStatus()` aggregate, `getChecks()`/`getCheck()` per-check, `getErrors()`/`hasErrors()`, `JsonSerializable` |
| `CheckResult` | DTO: `check` (`CheckName`), `status` (`CheckStatus`), `reason` (human-readable) |
| `CheckError` | DTO: `check` (`CheckName`), `message` — a check that ran but threw |
| `CheckName` | Enum: `Probe`, `Ssl`, `Whois`, `Dns`, `Content`, `Port`, `SecurityHeaders`, `RobotsTxt`, `Sitemap` |
| `CheckStatus` | Enum: `OK`, `WARNING`, `CRITICAL`, `UNKNOWN` |

## Security

- HTTP checks accept only `http` and `https` URLs.
- Host inputs are normalized and validated before use.
- `SslCertificateService` reads peer certificates in monitoring mode (`verify_peer: false`) — it does not validate the PKI trust chain.
- The package does not make any network requests on its own: it relies on user-provided PSR-18 clients and WHOIS instances.

## Examples

See [examples/](examples/) for runnable scripts.

| Script | Shows | Network? |
|---|---|---|
| `full-check.php` | Full domain check via `DomainMonitor` orchestrator | Yes |
| `http-probe.php` | HTTP probe + content check | Yes |
| `ssl-whois-dns.php` | SSL, WHOIS, and DNS | Yes |
| `port.php` | TCP port check with custom host/port | Yes |
| `security-headers.php` | Check security headers on a live URL | Yes |
| `robots.php` | Fetch `/robots.txt` and extract sitemaps | Yes |
| `sitemap.php` | Fetch sitemap and count URLs | Yes |
| `report.php` | Build a `DomainHealthReport` from DTOs | No |

Run examples:

```bash
php examples/port.php example.com 443
php examples/security-headers.php https://example.com
```

## Development

No PHP/Composer on the host — run in Docker via the `composer:2` image:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
```

Or with Make:

```bash
make install
make build
make cs-fix
make test
```

Integration tests (marked `@coversNothing`) skip unless `DOMAIN_MONITOR_NET=1` is set:

```bash
DOMAIN_MONITOR_NET=1 make test
```

## License

[BSD-3-Clause](LICENSE.md)
