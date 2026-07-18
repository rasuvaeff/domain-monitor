# Changelog

## Unreleased

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

