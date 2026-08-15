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

## TODO: sections

The following sections are added by their respective pull requests into the
`2.0` branch:

- [ ] Per-service interfaces (D2)
- [ ] Result-typed report slots (D1)
- [ ] Time budget and batch API (E2/E3)
