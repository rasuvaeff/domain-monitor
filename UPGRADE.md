# Upgrade 1.5 → 2.0

Breaking changes and step-by-step migration. Each section is standalone;
apply in any order. Code that does not touch the affected API needs no changes.

## Requirements

- PHP 8.3+ (unchanged)

## Strict thresholds by default (D3)

`ReportThresholds` now warns 30 days before SSL certificate expiry by default.
In 1.x the default had **no** SSL expiry warning (SSL became `CRITICAL` only
once expired), so `getStatus()` for a near-expiry certificate changes from
`OK` to `WARNING`.

```php
// 1.x default (no SSL warning window)
$report = new DomainHealthReport(host: 'example.com', ssl: $ssl); // near-expiry → WARNING now

// Opt out — restore the 1.x behaviour exactly:
$report = new DomainHealthReport(
    host: 'example.com',
    ssl: $ssl,
    thresholds: ReportThresholds::legacy(), // sslWarnDays: null
);

// Or explicitly per window:
new ReportThresholds(sslWarnDays: null);   // disable the SSL warning
new ReportThresholds(sslWarnDays: 60);     // widen it
```

`ReportThresholds::strict()` (`sslWarnDays: 14`) is unchanged.

## Per-service interfaces (D2)

Every service now implements a matching interface: `HttpProbeServiceInterface`,
`SslCertificateServiceInterface`, `WhoisServiceInterface`, `DnsServiceInterface`,
`PortServiceInterface`, `SecurityHeadersServiceInterface`, `RobotsTxtServiceInterface`,
`SitemapServiceInterface`, `HttpContentCheckServiceInterface`, `EmailSecurityServiceInterface`,
`TlsCipherServiceInterface`, `CookieSecurityServiceInterface`.

`DomainMonitor`'s constructor (and its promoted public properties) accept the
interfaces instead of the concrete classes. Swap or mock a single check without
touching the others:

```php
$monitor = new DomainMonitor(
    dns: new class implements DnsServiceInterface {
        #[\Override]
        public function check(string $host): DnsRecords { /* ... */ }
    },
);
```

Passing the shipped concrete services keeps working — they implement the
interfaces. Code that type-hinted the concrete classes in its own signatures
against `DomainMonitor`'s properties should switch to the interfaces.

## Result-typed report slots (D1)

Every check slot on `DomainHealthReport` is now a
`Result<XxxCheck, CheckError>|null` (`rasuvaeff/result` is a new runtime
dependency): `Ok` carries the payload DTO, `Err` carries the `CheckError` that
replaced it, `null` still means the check was not configured. The separate
`errors` constructor parameter is gone — errors live in the slots, and
`getErrors()`/`hasErrors()` are derived from them.

```php
// 1.x: slot was the DTO (or null on failure — indistinguishable from disabled)
$cert = $report->ssl;              // ?SslCertificate

// 2.0: slot is a Result
$slot = $report->ssl;              // Result<SslCertificate, CheckError>|null
$cert = $slot?->unwrap();          // SslCertificate when Ok
$error = $slot?->error();          // CheckError when Err
```

- Constructing a report with bare DTOs keeps working (auto-wrapped into `Ok`).
  Only **reading** slots changed: unwrap first.
- `jsonSerialize()` keeps the same top-level shape; `Err` slots serialize as
  their `CheckError`, the `errors` key is derived.
- A service returning `null` (SSL/WHOIS lookup failure) now produces an `Err`
  slot ("Service returned no result") instead of an invisible `null` slot.
- `getChecks()` ordering: failed checks appear in their slot position instead
  of being appended after successful ones.

## TODO: sections

The following sections are added by their respective pull requests into the
`2.0` branch:

- [ ] Time budget and batch API (E2/E3)
