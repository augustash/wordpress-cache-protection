# WordPress Cache Protection

Protects WordPress page cache from fragmentation by tracking parameters and bot abuse.

## Install

```sh
ddev composer require augustash/wordpress-cache-protection && ddev wp plugin activate wordpress-cache-protection
```

## What it does

Hooks at plugin load and runs before WordPress queries posts.

Two modes for handling cache-busting query params:

- **Redirect** — 301 → clean URL. Varnish caches the 301, so subsequent dirty hits cost no PHP. Used for params that on-site JS doesn't read from `window.location` (default: `srsltid`, `fbclid`).
- **Strip** — internal rewrite of `$_GET` / `REQUEST_URI`. Browser URL stays dirty so client-side analytics/ads scripts can still read attribution from `window.location`; the application sees clean. Used for default: `gclid`, `msclkid`, `_kx`, `gbraid`, `gad_source`, `gad_campaignid`, `utm_*`, `hsa_*`, `_hsmi`, `_hsenc`, `__hstc`, `__hssc`, `__hsfp`.

Also strips Pantheon's `*=PANTHEON_STRIPPED` leftovers.

Augments `robots.txt` with bot-throttling rules for known abuse patterns.

## Search protection

Per-IP rate-limit + page-cache kill on search responses. Every search query is unique, so the page cache can never help; meanwhile bots blast random queries to fragment cache and stress the DB.

- Two flood windows: burst (default 5/10s) + sustained (default 30/60s). Either limit triggers 429.
- Cache-Control: `private, no-store` on search responses so Varnish never caches them.
- Empty `?s=` (bare search form) doesn't trigger — stays cacheable.
- Default search param: `s` (WP core). Configurable.

Enabled by default; toggle off in Settings → Cache Protection.

## Configuration

Settings → Cache Protection in the WP admin. Two textareas for redirect and strip lists, one param per line. Trailing `*` for prefix wildcards (e.g. `utm_*`). Search protection has its own section with the windows/thresholds.

## Testing

```sh
ddev exec bash -c 'cd wp-content/plugins/wordpress-cache-protection && composer install && vendor/bin/phpunit'
```
