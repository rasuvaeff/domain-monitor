# Changelog

## 2.0.0 — 2026-08-15

Breaking major. See [UPGRADE.md](UPGRADE.md) for step-by-step migration.

### 2.0 plumbing

- CI: the `Backward compatibility` step learns the intended-major tolerance
  (a dated `## X.0.0` heading in this file over the latest tag passes code 3),
  so breaking PRs for this release are gated, not blindly waved through.
- `UPGRADE.md` added: per-step migration for every breaking change below.

### Time budget and batch API (E2/E3)

- `DomainMonitorOptions::maxDuration: ?Duration` caps a single `check()` run;
  past the deadline every remaining check becomes an `Err` slot
  ("Time budget exceeded"). `null` (default) = no budget.
- `DomainMonitorInterface::checkMany(hosts:, options:)` returns
  `array<string, DomainHealthReport>` keyed by normalized host; sequential,
  fresh budget per host.

### Result-typed report slots (D1)

- `rasuvaeff/result` is now a runtime dependency.
- Every `DomainHealthReport` check slot is `Result<XxxCheck, CheckError>|null`:
  `Ok` = payload DTO, `Err` = `CheckError`, `null` = check not configured. The
  `errors` constructor parameter is removed; `getErrors()`/`hasErrors()` are
  derived from the `Err` slots.
- The constructor still accepts bare DTOs (auto-wrapped into `Ok`); reading
  slots requires unwrapping.
- Services returning `null` (SSL/WHOIS lookup failure) now produce `Err` slots
  instead of invisible `null` slots.
- `jsonSerialize()` keeps the shape; `Err` slots serialize as `CheckError`,
  `errors` key is derived.

### Per-service interfaces (D2)

- Every service implements a matching `*ServiceInterface`; `DomainMonitor`'s
  constructor and promoted properties accept the interfaces, so a single check
  can be swapped/mocked without touching the others.
- Concrete services keep working as-is (they implement the interfaces).

### Strict thresholds by default (D3)

- `ReportThresholds` default flips to `sslWarnDays: 30` (was `null`): SSL
  becomes `WARNING` 30 days before expiry. `getStatus()` for near-expiry
  certificates changes from `OK` to `WARNING`.
- `ReportThresholds::legacy()` restores the 1.x behaviour (`sslWarnDays: null`).
- `ReportThresholds::strict()` (`sslWarnDays: 14`) unchanged; explicit
  `sslWarnDays: null` still disables the window.

## 1.5.0 — 2026-08-15

### Retries (roadmap B1)

- `DomainMonitorOptions` accepts an optional `retry: ?Rasuvaeff\Retry\Retry`
  policy (`rasuvaeff/retry` is now a runtime dependency). Every check —
  including the HTTP probe — is wrapped in the policy when configured.
- `null` (default) keeps the legacy single-attempt behaviour.
- On exhaustion the `RetryExhausted` message (attempt count + last error) is
  recorded as that check's `CheckError`; probe exhaustion is treated like a
  probe failure (`status: 0`, response-dependent checks skipped).
- Non-retryable exceptions are rethrown as-is and land in `getErrors()` as before.

### Circuit breaker (roadmap B2)

- `DomainMonitorOptions` accepts an optional `circuitBreaker: ?Rasuvaeff\CircuitBreaker\CircuitBreaker`
  policy (`rasuvaeff/circuit-breaker` is now a runtime dependency). When set,
  the whole check run is wrapped in `$breaker->call()`.
- `check()` never throws — observe outcomes via `BreakerConfig::classifyResult`
  (classify the returned `DomainHealthReport`, e.g. CRITICAL = failure).
- When the circuit is open, further calls skip all services and return a report
  with a single `CheckError` (probe, "Circuit ... is open, retry after ...").
- Per-host registry (host => breaker) is the caller's concern.

### Typed timeouts (roadmap B3)

- `rasuvaeff/duration` is now a runtime dependency.
- `DomainMonitorOptions` and `HttpProbeOptions` get `withTimeout(Duration): self`,
  which overrides the constructor's `timeoutSeconds: float`; the resolved value
  is exposed as the same `timeoutSeconds` property the services already consume.
  Replacing the float params is deferred to 2.0.

## 1.4.0 — 2026-07-29

### EmailSecurityService (SPF / DKIM / DMARC / CAA / MX)

- New `EmailSecurityService` + `EmailSecurityCheck` inspect a host's email-security
  DNS posture: SPF (TXT at root), DMARC (TXT at `_dmarc.{host}`, with `p=`
  policy extraction), DKIM (TXT at `{selector}._domainkey.{host}` for selectors
  supplied to the constructor — pass `null`/omit to skip DKIM), CAA (`issue` tag)
  and MX. Status follows worst-wins:
  - `OK` — SPF + DMARC published, or no MX and no SPF/DMARC (host not configured for inbound email).
  - `WARNING` — mail accepted (MX present) but SPF or DMARC missing; or no MX with only one of SPF/DMARC.
  - `CRITICAL` — mail accepted but neither SPF nor DMARC published.
  - `UNKNOWN` — DNS lookup failed.
  The service is pure-DNS (no HTTP client required) and accepts a `callable`
  resolver for tests, mirroring `DnsService`.

### TlsCipherService (protocol version + cipher suite)

- New `TlsCipherService` + `TlsCipherCheck`: performs a TLS handshake and reports
  the negotiated protocol version and cipher name. Flags deprecated protocols
  (TLS 1.0 / 1.1 / SSLv2 / SSLv3 → `CRITICAL`) and weak cipher patterns
  (RC4 / 3DES / DES-CBC / NULL / EXPORT / MD5 / RC2 / IDEA / SEED / ARIA / GOST
  → `WARNING` when on TLS 1.2+). Certificate chain is not validated — the
  service mirrors `SslCertificateService` (monitoring, not PKI). Callable
  connector injection for tests; PSR-3 logger on handshake failure.

### CookieSecurityService (Set-Cookie audit)

- New `CookieSecurityService` + `CookieSecurityCheck`: audits every `Set-Cookie`
  header on a PSR-7 response. A cookie is flagged insecure when missing `Secure`,
  missing `HttpOnly`, or carrying the `__Host-` prefix without `Path=/` /
  with `Domain`. `OK` when no cookies are flagged (or no Set-Cookie headers at
  all), `WARNING` otherwise. Reuses the probe response — no extra request.

### Shared plumbing

- New `CheckName` cases: `EmailSecurity`, `TlsCipher`, `CookieSecurity`.
- `DomainMonitor` constructor gains three nullable services (additive, default-disabled);
  `DomainMonitor::create()` factory wires all three by default;
  `DomainMonitorBuilder::withoutEmailSecurity()` / `withoutTlsCipher()` /
  `withoutCookieSecurity()` disable them. `CookieSecurityService` (like
  `SecurityHeadersService`) requires `HttpProbeService` and raises
  `InvalidArgumentException` if wired alone.
- `DomainHealthReport` carries three new nullable fields, exposed via
  `getChecks()` / `getCheck(CheckName::*)` and serialised.
- New runnable examples: `examples/email-security.php`, `examples/tls-cipher.php`,
  `examples/cookie-security.php`.
- README (EN + RU) and `llms.txt` document the new checks, status rules and API
  reference entries.

## 1.3.2 — 2026-07-29

- Fix PSR-7 named-argument incompatibility: HTTP-based services
  (`HttpProbeService`, `RobotsTxtService`, `SitemapService`,
  `HttpContentCheckService`, `SecurityHeadersService`) called
  `MessageInterface::withHeader(name:, value:)` / `hasHeader(name:)` using the
  parameter names declared on the PSR-7 interface, but real implementations
  (`nyholm/psr7`, `guzzle/psr7`, `laminas/laminas-diactoros`) name the parameter
  `$header` and throw `Unknown named parameter $name` at runtime. Switched to
  positional arguments. The bug was masked because unit tests exercise these
  services through fakes whose signatures happened to match.
- Add `symfony/http-client` to `require-dev` so the HTTP-based examples
  (`full-check`, `http-probe`, `robots`, `security-headers`, `sitemap`) are
  runnable from a fresh `composer install`. Previously they referenced
  `Symfony\Component\HttpClient\Psr18Client` without the dependency installed.
- Bump `rasuvaeff/property-testing` from `^1.0` to `^2.7` and convert
  `*Generators` helpers to `public static` (Rector's dead-code set deletes
  private ones called only via reflection).
- New property-based tests: arbitrary-report comparator invariants
  (`diffOfArbitraryReportWithItselfIsEmpty`,
  `forwardAndBackwardTransitionsAreInverse`, `worstTransitionCarriesMaxSeverity`),
  SSL time-monotonicity (`isExpiredIsMonotonicByNow`,
  `daysUntilExpiryStaysWithinCertificateLifetime`),
  `CheckStatus` severity collision, and IDN round-trip.

## 1.3.1 — 2026-07-25

- Reject trailing newlines in HTTP-method validation: anchor `METHOD_PATTERN`
  in `HttpProbeOptions` and `DomainMonitorOptions` with `\z` instead of `$`
  (PCRE `$` matches before a trailing `\n`, which let `"GET\n"` pass and reach
  the PSR-18 request factory).
- Hygiene: `HostNormalizer::HOST_PATTERN` also anchored with `\z` (both the
  lookahead length bound and the subject end). `normalizeHost()` already
  `trim()`s the host before the regex, so the change is not observable through
  the public API.

## 1.3.0 — 2026-07-18

- `ReportComparator` diffs two `DomainHealthReport` snapshots of the same host into a `list<StatusTransition>` (or a `ReportDiff` wrapper via `compare()`), turning stateless snapshots into alertable status changes without adding storage to the package.
- New `StatusTransition` DTO (`check`, `from`, `to`, `kind`) and `TransitionKind` enum (`appeared`, `disappeared`, `degraded`, `recovered`, `changed`); transitions to/from `UNKNOWN` are reported as `changed` (severity is not comparable). All new types implement `JsonSerializable`.
- New `CheckStatus::severity()` exposes the aggregate worst-wins ordering (`UNKNOWN` lowest).

## 1.2.0 — 2026-07-05

- `DomainHealthReport::getChecks()` returns a `list<CheckResult>` — one per executed check with its `CheckStatus` and a human-readable `reason`; `getCheck(CheckName)` looks one up. `getStatus()` is now derived from this list (unchanged aggregate values).
- New `CheckName` enum and `CheckResult` DTO.
- All result DTOs (`DomainHealthReport`, `ProbeResult`, `SslCertificate`, `TldInfo`, `DnsRecords`, `HttpContentCheck`, `PortCheck`, `SecurityHeadersCheck`, `RobotsTxtCheck`, `SitemapCheck`, `CheckResult`, `CheckError`, `ReportThresholds`) implement `JsonSerializable` — `json_encode($report)` yields a complete snapshot (dates as ISO-8601, enums as their values).
- `ReportThresholds` VO makes SSL "expiring soon" and the WHOIS warning window configurable (opt-in via `DomainMonitorOptions`/`DomainHealthReport`; `default()` preserves 1.1.x behaviour, `strict()` warns 14 days before SSL expiry).
- `CheckError` + `DomainHealthReport::getErrors()`/`hasErrors()` distinguish a check that errored (reported as `UNKNOWN`, never inflating the aggregate) from one that was disabled. `DomainMonitor` records per-check errors instead of silently dropping them.
- `DomainMonitorInterface` (implemented by `DomainMonitor`) enables mocking and decoration.
- `DomainMonitor::create()` factory wires every check from a single PSR-18 client + PSR-17 factory (+ optional WHOIS); `DomainMonitorBuilder` offers granular, fluent composition.

## 1.1.2 — 2026-06-30

- Add `/benchmarks` and `/Makefile` to `.gitattributes` export-ignore.

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.1.1 — 2026-06-27

- Migrate test suite from PHPUnit to Testo. Internal change, no public API impact.

## 1.1.0 — 2026-06-16

- `DomainMonitor` orchestrator: runs all configured services in one call and assembles a `DomainHealthReport`. Services are optional (null = disabled). Failed checks are caught, logged via PSR-3, and omitted from the report.
- `DomainMonitorOptions` VO for orchestrator configuration (port, timeout, HTTP method, userAgent, expectedOrg, expectedStatus, requiredText, forbiddenText).
- `HttpProbeService::probeWithResponse()` — returns `HttpProbeWithResponse` DTO containing both `ProbeResult` and the raw PSR-7 `ResponseInterface` for response reuse.
- `HttpContentCheckService::checkFromResponse()` — verifies content from a pre-fetched PSR-7 response without making an additional HTTP request.
- `DomainMonitor` reuses a single HTTP response for probe + security headers + content check (3 requests → 1).

## 1.0.0 — 2026-06-01

- Initial release.
- `HttpProbeService`, `HttpContentCheckService` — PSR-18/PSR-17 based HTTP probing and content checks.
- `SslCertificateService` — best-effort SSL certificate inspection with `mapCertInfo()` seam.
- `WhoisService` — WHOIS lookups mapped to a package-owned `TldInfo` DTO.
- `DnsService` — A/AAAA/MX/NS/TXT/CNAME lookups via an injectable resolver.
- `PortService` — TCP port availability via `stream_socket_client()`.
- `SecurityHeadersService` — HSTS/CSP/X-Frame-Options/X-Content-Type-Options evaluation.
- `RobotsTxtService`, `SitemapService` — `robots.txt` and sitemap availability checks.
- `HostNormalizer` — single normalization point for host/URL inputs with optional IDN support.
- `DomainHealthReport`, `CheckStatus` — composite report container and unified status enum.

