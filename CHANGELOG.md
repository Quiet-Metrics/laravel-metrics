# Changelog

All notable changes to `quiet-metrics/laravel-metrics` are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning: [SemVer](https://semver.org).

## [0.2.0] - 2026-08-28

### Added
- Opt-out marker: a visitor loading any page of the tracked site with `?qm_ignore=1` stops being counted, and `?qm_ignore=0` puts them back into measurement. The marker is a first-party `qm_ignore` cookie of that site (`path=/`, `samesite=lax`, `secure` over https, five years); it holds no identifier, is never transmitted to Quiet Metrics, and exists only to stop measurement. Nothing is sent while it is present.

### Changed
- The published promise is now "no identification or tracking cookies" rather than "cookie-free". Nothing is stored on the visitor's device in order to measure them; the one exception is the opt-out marker, which they store themselves and which is exempt from consent as an expression of refusal.
- README: the package is on Packagist, so installing it no longer needs a VCS repository entry.
- **The opt-out marker no longer depends on the tracking middleware.** It used to be set by `TrackPageview`, which is applied route by route: an application sending only manual events, or tracking just some of its pages, left `?qm_ignore=1` with no effect, while reading the refusal kept working. A visitor could therefore stay excluded if they had opted out elsewhere, but could no longer opt out here. A refusal mechanism does not depend on a measurement option. The marker now lives in its own `HandleOptOut` middleware, pushed onto the kernel's global stack by the service provider, with the `quiet-metrics-optout` alias for manual wiring and `quiet-metrics.register_opt_out_middleware` to turn the automatic registration off.
- Requires `quiet-metrics/php-metrics` `^0.2`: under 0.x, `^0.1` excludes 0.2.

### Note for upgraders
The provider now registers one global middleware. It does nothing on a request without the `?qm_ignore` parameter, and `register_opt_out_middleware` disables it. Global rather than the `web` group on purpose: `pushMiddlewareToGroup('web', ...)` from a provider has no effect under Laravel 11+, where groups are built by the application bootstrap. Verified, the middleware appeared in `getMiddlewareGroups()` but its `handle()` was never called.

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
