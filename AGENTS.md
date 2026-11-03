# AGENTS.md — domain-monitor

Guidance for AI agents working on this package. Read before changing code.

## What this is

A domain monitoring toolkit for PHP 8.3+ under the `Rasuvaeff\DomainMonitor\`
namespace. It provides small immutable DTOs and focused services for HTTP probes,
SSL certificate inspection, WHOIS, DNS lookups, TCP port checks, security-header
evaluation, `robots.txt`, and sitemap inspection.

The package deliberately does not include scheduling, caching, persistence, or a
“run all checks” orchestrator. Applications compose services themselves and may
store the results in `DomainHealthReport`.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **Normalize and validate every host/URL input** through `HostNormalizer`; reject empty/invalid input with `InvalidArgumentException`.
4. **Preserve the public contract.** Update README + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
```

Or with Make:

```bash
make build
make cs-fix
make psalm
make test
```

`composer.lock` is gitignored (library).

## Invariants & gotchas

- Public DTOs and services are `final readonly class` unless mutability is truly required.
- `HostNormalizer` is the single normalization point for host and URL inputs.
- `HttpProbeOptions::timeoutSeconds` is best-effort only; PSR-18 has no standard timeout API.
- `SslCertificateService` reads certificates best-effort and does not validate trust.
- `WhoisService` maps vendor `TldInfo` to package `TldInfo`; do not leak vendor DTOs in the public API.
- Code: `declare(strict_types=1)`, `#[\Override]`, explicit types.
- **Mutation testing** runs with `--with-uncovered` and `minMsi` 92. Measured with
  `zend.assertions=-1` as in CI: 1226 mutants, 18 Ignored, 57 undetected, overall
  MSI 95%. The undetected ones are not worth tests:
  - `TlsCipherService::defaultConnector()` (a real TLS handshake; unlike the other
    default connectors it is not in `global-ignore`, its 19 mutants are Not Covered).
  - `HostNormalizer::normalizeAsciiHost()` fallbacks for `IDNA_DEFAULT` /
    `INTL_IDNA_VARIANT_UTS46` (always defined with ext-intl) and `idn_to_ascii()`
    returning `false` (the host regex rejects such input first); the empty-host
    throw after `parse_url()` (it returns `false` first).
  - `WhoisService` `status`/`statuses` fallbacks: vendor `toArray()` only returns
    `states`; `readArrayList()`'s `array_values` (the caller appends anyway).
  - Identity or warning-only mutants: `DomainHealthReport::getStatus()` `>`/`>=`
    (equal severity is the same enum), `DomainMonitor::deadlineHit()` `>=`,
    `ReportComparator`/`ReportDiff` severity ties, `libxml` finally/clear (setting
    `use_internal_errors(false)` clears the buffer), `explode(..., limit)` of a
    cookie name, `DomainMonitorBuilder::build()` `&&` (client and factory are set
    together), `EmailSecurityService` early returns that fall through to the same
    result, `SitemapService` `count` fallbacks (`xpath()` does not fail on a parsed
    document), `RobotsTxtService::buildRobotsUrl()` fallback (the URL is
    normalized before it).

## Roadmap (deferred to a future 2.0 — breaking)

Consumer-gap work landed additively in 1.2.0 (per-check `getChecks()`/reasons,
`JsonSerializable`, `ReportThresholds`, `CheckError`, `DomainMonitorInterface` +
`create()`/`DomainMonitorBuilder`). Two follow-ups are intentionally held back
because they cannot be done without a breaking change:

- **Strict thresholds by default.** Flip `ReportThresholds::default()` to
  `sslWarnDays: 30` ("secure by default"). Changes `getStatus()` for
  near-expiry certificates → behavioural break → major only.
- **Per-service interfaces.** Introduce `HttpProbeServiceInterface`,
  `SslCertificateServiceInterface`, … and widen `DomainMonitor`'s constructor to
  accept them (swap/mock a single check). Widening concrete → interface is safe
  for callers but `roave/backward-compatibility-check` flags the signature
  change → major only. 5a (`DomainMonitorInterface`) already covers
  mock/decorate at the orchestrator seam, so this is low priority.

## When you finish

- Update `README.md` and `examples/` if usage changed; update `CHANGELOG.md` when releasing.
- Re-run `composer build` and paste the output.
