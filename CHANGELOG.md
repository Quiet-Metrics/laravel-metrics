# Changelog

All notable changes to `quiet-metrics/laravel-metrics` are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning: [SemVer](https://semver.org).

## [Unreleased]

## [0.1.0] - 2026-07-24

First tagged release (private beta), after a full pre-publication review.

### Added
- `TrackPageview` middleware (alias `quiet-metrics`): automatic server-side pageviews sent in `terminate()`, after the response has left; only successful HTML `GET`s are counted.
- `QuietMetrics` facade and `Client` singleton for custom events.
- Publishable `quiet-metrics` configuration; context read from the `Request` object (Octane-safe, trusted-proxies aware).
- Covered by the core SDK's platform contract tests (same `Client`).

### Fixed
- Default endpoint now `https://quietmetrics.dev/api/v1/collect`.
- Documentation makes the secret key explicitly essential for server-side sending (unsigned hits collapse all visitors into the sending server's IP).
