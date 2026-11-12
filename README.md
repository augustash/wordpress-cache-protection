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
- Counts per visitor, not per proxy. Behind Cloudflare the connecting IP is an edge shared by a whole region, so when the request comes from a Cloudflare range the limit keys on `CF-Connecting-IP`; from anywhere else that header is ignored, so it can't be forged to dodge the limit. Add other trusted proxy ranges with the `wpcp_trusted_proxies` filter.

Enabled by default; toggle off in Settings → Cache Protection.

## SearchWP indexing

SearchWP 4 indexes by POSTing to the site's own `admin-ajax.php`, one batch per request. Each batch holds a PHP-FPM worker, and the dispatching request often holds a second one waiting. On Pantheon the call leaves by the public hostname, so behind Cloudflare it goes out and back in. On a Basic plan (4 workers) one indexing run is half the pool, every time staff edit.

With SearchWP active, this plugin:

- Never starts SearchWP's background process from a web request (`searchwp\index\process\enabled` is true only under WP-CLI).
- Skips SearchWP's blocking HTTP self-test on save (`searchwp\indexer\alternate`).
- Drops a saved entry from the index at once (`searchwp\index\aggressive_delta`) and re-indexes it in the same request after `fastcgi_finish_request()`. Searchable immediately; no second worker.
- Schedules an hourly sweep (`\SearchWP\CLI::index()`) that only runs under WP-CLI. Pantheon runs WP-Cron through WP-CLI, outside the web pool.

SearchWP's settings screen will say the indexer is paused. That's true for web requests, and expected. On a host that doesn't run WP-Cron from WP-CLI, schedule `wp cron event run --due-now` or `wp searchwp index` yourself.

Enabled by default; toggle off in Settings → Cache Protection, or with the `wpcp_searchwp_indexing_enabled` filter.

## Not covered: Dynamics 365 email parameters

Dynamics 365 marketing emails add `_cldee`, `recipientid` and `esid` to every link, unique per recipient. Stripping them here doesn't help the cache: Pantheon's edge keys on the URL before WordPress runs. Remove them at the CDN instead (a Cloudflare URL Rewrite rule).

## Configuration

Settings → Cache Protection in the WP admin. Two textareas for redirect and strip lists, one param per line. Trailing `*` for prefix wildcards (e.g. `utm_*`). Search protection has its own section with the windows/thresholds.

## Testing

```sh
ddev exec bash -c 'cd wp-content/plugins/wordpress-cache-protection && composer install && vendor/bin/phpunit'
```
