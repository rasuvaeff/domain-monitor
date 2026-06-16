# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

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
