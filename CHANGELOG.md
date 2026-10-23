# Changelog

All notable changes to `quiet-metrics/laravel-metrics` are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning: [SemVer](https://semver.org).

## [0.7.0] - Unreleased

### Added
- **Optional Filament dashboard widget**, `QuietMetrics\Laravel\Filament\QuietMetricsWidget`, for Filament 4 or 5: daily visitors (summed), page views and sessions over the last 30 days with their change on the previous period, a visitors trend, the five most viewed pages, and a link to Quiet Metrics. Read server side from the read API with a new `read_token` key (`QUIET_METRICS_READ_TOKEN`), a token restricted to the site and separate from the collection keys. Responses are cached ten minutes under a hashed key; errors show a neutral message. English and French translations.
- `filament/filament` is suggested, not required: without Filament, nothing changes.

## [0.6.0] - 2026-09-28

### Added
- **SEO crawl.** New `seo_crawl` config key, read from `QUIET_METRICS_SEO_CRAWL` and `false` by default. When it is `true` and a secret key is set, the bridge serves the site ownership proof the Quiet Metrics SEO tab requires before crawling, at `/.well-known/quietmetrics.json` (`GET` and `HEAD`, `application/json`, `Cache-Control: no-store`). Otherwise nothing is registered and the path stays with the application.
- The answer comes from a `ServeSiteVerification` middleware pushed onto the kernel's global stack by the provider, only when the document exists. A global middleware rather than a route: it runs before routing, outside the `web` group (no session), is never measured, and does not depend on `route:cache`, which would freeze or drop a route declared from a provider.

### Changed
- Requires `quiet-metrics/php-metrics` `^0.6`.

## [0.5.0] - 2026-09-24

### Added
- `QuietMetrics::fake()` replaces the client with a fake that records instead of sending, in the facade and the container: the middleware's pageviews go through it too. Assertions: `assertPageviewSent()`, `assertEventSent()`, `assertEventNotSent()`, `assertNothingSent()`; readers: `pageviews()`, `events()`. The fake records calls as received, before minimization.
- `QuietMetrics\Tracker` is bound to the `Client` singleton; the facade and `TrackPageview` depend on it.

### Fixed
- The visit cookie now follows the pageview when a priority route middleware fails before `TrackPageview` (a missing route model binding, for instance): the provider moves `TrackPageview` to the front of the middleware priority, so `handle()` sees the same response as `terminate()`. Previously the pageview was sent without `qm_visit`, and the visitor's next page looked like a new visit. Verified in a fresh Laravel 13 application.

### Changed
- Requires `quiet-metrics/php-metrics` `^0.5`: the page address and referrer are minimized before sending (see the core changelog). No figure changes.

## [0.4.0] - 2026-09-15

### Documentation
- Platform 1.8.0 now lists the top ten paths reported by events named `404`, with counts and previous-period changes. The dashboard uses the event URL's normalized path, dashboard filters and retained history; enabling tracking does not reconstruct older errors. The collection diagnostic keeps rejection and quota counts visible when collapsed.

### Fixed
- Inspect the response Content-Type, not only the request's Accept header: PDF, JSON and file downloads no longer count as pageviews or open a visit window.

### Changed
- HTML/XHTML GET responses with 4xx/5xx status now count as pageviews, like the JavaScript tracker. Redirects, 204/205 responses, AJAX, prefetch and opt-out requests remain excluded.
- Requires `quiet-metrics/php-metrics` `^0.4` for the shared response policy. This changes the measured population: HTML error pages are added and non-HTML responses removed, with no historical rewrite.

### Added
- Opt-in `track_404` / `QUIET_METRICS_TRACK_404=true`: adds a `404` event with the path alongside the error pageview. Disabled by default; enabling it consumes an additional quota event per 404. Use either this option or the JavaScript `data-404` option for a given page, not both.

## [0.3.0] - 2026-08-28

### Added
- **Visit continuity cookie.** The visitor fingerprint is recomputed on every hit from the subscriber network and the browser. When that network changes MID-VISIT (switching from mobile data to wifi), the fingerprint changes and the same person was counted as two unique visitors on the same day. `qm_visit` closes that: a first-party cookie of the tracked site, value `1`, sliding ten-minute window refreshed on each hit (`path=/`, `samesite=lax`, `secure` over https). It holds no identifier, its value being the same for everyone, and it is never set for someone who has opted out. Only its presence travels, as the `c` boolean of the payload.

### Changed
- `TrackPageview::handle()` sets the cookie on the RESPONSE (terminate is too late for a `Set-Cookie`), behind a single `measures()` predicate shared with `terminate()`. The cookie is excepted from Laravel's cookie encryption, without which the JS tracker of the same site would not recognise the window.
- Requires `quiet-metrics/php-metrics` `^0.3`.

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
