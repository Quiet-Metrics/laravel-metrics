# Changelog

All notable changes to `quiet-metrics/laravel-metrics` are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning: [SemVer](https://semver.org).

## [Unreleased]

### Added
- Opt-out marker: a visitor loading any page of the tracked site with `?qm_ignore=1` stops being counted, and `?qm_ignore=0` puts them back into measurement. The marker is a first-party `qm_ignore` cookie of that site (`path=/`, `samesite=lax`, `secure` over https, five years); it holds no identifier, is never transmitted to Quiet Metrics, and exists only to stop measurement. Nothing is sent while it is present.

### Changed
- The published promise is now "no identification or tracking cookies" rather than "cookie-free". Nothing is stored on the visitor's device in order to measure them; the one exception is the opt-out marker, which they store themselves and which is exempt from consent as an expression of refusal.
- README: the package is on Packagist, so installing it no longer needs a VCS repository entry.

## [0.1.1] - 2026-08-27

Documentation and artwork only. The bridge code is unchanged since 0.1.0.

### Changed
- Banner redrawn to the current brand: product typefaces, the damped wave, title in ink.
- README: the pre-Packagist install note no longer says that access to the repositories is required. Both are public.

### Fixed
- The development `repositories` entry now pins a resolvable version of the core package.

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
