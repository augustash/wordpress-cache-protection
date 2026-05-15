<?php
/**
 * Cache protection core: strip/redirect tracking params on inbound requests.
 *
 * @package Augustash\WordpressCacheProtection
 */

namespace Augustash\WordpressCacheProtection;

if (! defined('ABSPATH')) {
	exit;
}

class CacheProtection {

	/**
	 * Default params to 301 → clean URL. Safe to redirect because on-site JS
	 * doesn't read these from window.location for conversion tracking.
	 */
	const DEFAULT_REDIRECT_PARAMS = [
		'srsltid',
		'fbclid',
	];

	/**
	 * Default params to internally strip — browser URL stays, app sees clean.
	 * Client-side JS reads these from window.location for analytics/ads.
	 * Supports trailing-* wildcards for prefix matching.
	 */
	const DEFAULT_STRIP_PARAMS = [
		'gclid',
		'msclkid',
		'_kx',
		'gbraid',
		'gad_source',
		'gad_campaignid',
		'utm_source',
		'utm_medium',
		'utm_campaign',
		'utm_term',
		'utm_content',
		'utm_id',
		'hsa_*',
		'_hsmi',
		'_hsenc',
		'__hstc',
		'__hssc',
		'__hsfp',
	];

	/**
	 * Pantheon's Varnish replaces stripped param values with this literal.
	 * We sweep these out regardless of whether the key is in our lists.
	 */
	const PANTHEON_STRIPPED_VALUE = 'PANTHEON_STRIPPED';

	public static function boot(): void {
		if (self::should_skip()) {
			return;
		}

		$query = self::parse_query_pairs($_SERVER['QUERY_STRING'] ?? '');
		if (empty($query)) {
			return;
		}

		$redirect_params  = self::param_list('wpcp_redirect_params', 'wpcp_redirect_params_option', self::DEFAULT_REDIRECT_PARAMS);
		$strip_params     = self::param_list('wpcp_strip_params', 'wpcp_strip_params_option', self::DEFAULT_STRIP_PARAMS);
		$preserved_params = apply_filters('wpcp_preserved_params', []);

		// PANTHEON_STRIPPED cleanup is unconditional — those keys are dead weight.
		[$stripped_pantheon, $query] = self::pull_pantheon_stripped($query, $preserved_params);

		[$to_redirect, $query]       = self::pull_matching($query, $redirect_params, $preserved_params);
		[$to_strip, $query]          = self::pull_matching($query, $strip_params, $preserved_params);

		// Redirect mode wins: if any redirect candidates were present, send
		// the user to the clean URL (which also drops the strip params).
		// Varnish caches the 301 → subsequent dirty hits are free.
		if (! empty($to_redirect)) {
			self::redirect_to_canonical($query);
			return;
		}

		// Strip mode: rewrite $_GET + REQUEST_URI in place so the app sees a
		// canonical URL but the browser stays on the original.
		if (! empty($to_strip) || ! empty($stripped_pantheon)) {
			self::rewrite_request($query);
		}
	}

	/**
	 * Per-request skip rules. Site-specific extensions via filter.
	 */
	protected static function should_skip(): bool {
		// Anything that mutates state must not be redirected/rewritten.
		if (! isset($_SERVER['REQUEST_METHOD']) || strtoupper($_SERVER['REQUEST_METHOD']) !== 'GET') {
			return true;
		}

		$uri = $_SERVER['REQUEST_URI'] ?? '';

		if ($uri === '' || $uri[0] !== '/') {
			return true;
		}

		// Path-based skips. We don't have is_admin() yet (WP hasn't booted)
		// so match the URL pattern directly.
		$path = parse_url($uri, PHP_URL_PATH) ?: '';

		if (strpos($path, '/wp-admin/') === 0) {
			return true;
		}
		if ($path === '/wp-login.php' || $path === '/wp-cron.php' || $path === '/xmlrpc.php') {
			return true;
		}
		// Theme-level AJAX endpoints: wp-content/themes/<theme>/ajax/<file>.php
		if (preg_match('#^/wp-content/themes/[^/]+/ajax/[^/]+\.php$#', $path) === 1) {
			return true;
		}
		// REST API
		if (strpos($path, '/wp-json/') === 0) {
			return true;
		}

		// Allow site to add custom skip conditions.
		return (bool) apply_filters('wpcp_should_skip', false, $path, $uri);
	}

	/**
	 * Parse a query string into ordered [key, value, raw-chunk] triples.
	 * Pairs (not assoc) preserve repeated keys like `f[]=a&f[]=b`.
	 * Public for testability — pure function over a string.
	 */
	public static function parse_query_pairs(string $qs): array {
		if ($qs === '') {
			return [];
		}

		$pairs = [];
		foreach (explode('&', $qs) as $chunk) {
			if ($chunk === '') {
				continue;
			}
			$eq    = strpos($chunk, '=');
			$key   = $eq === false ? $chunk : substr($chunk, 0, $eq);
			$value = $eq === false ? '' : substr($chunk, $eq + 1);
			$pairs[] = [urldecode($key), urldecode($value), $chunk];
		}
		return $pairs;
	}

	/**
	 * Read a param list from a filter, falling back to the stored option,
	 * falling back to defaults. Site-side filter wins so dev code beats DB.
	 */
	protected static function param_list(string $filter, string $option, array $defaults): array {
		$stored = get_option($option, null);
		$list   = is_array($stored) ? $stored : $defaults;
		return (array) apply_filters($filter, $list);
	}

	/**
	 * Match a key against a list of patterns. Supports trailing-* wildcards.
	 * Public for testability — pure function.
	 */
	public static function key_matches(string $key, array $patterns): bool {
		foreach ($patterns as $pattern) {
			$pattern = trim((string) $pattern);
			if ($pattern === '') {
				continue;
			}
			if (substr($pattern, -1) === '*') {
				$prefix = substr($pattern, 0, -1);
				if ($prefix !== '' && strpos($key, $prefix) === 0) {
					return true;
				}
				continue;
			}
			if ($key === $pattern) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Pull pairs whose key matches any pattern in $patterns (respecting preserves).
	 * Returns [pulled_pairs, remaining_pairs]. Public for testability.
	 */
	public static function pull_matching(array $pairs, array $patterns, array $preserved): array {
		if (empty($patterns)) {
			return [[], $pairs];
		}
		$pulled    = [];
		$remaining = [];
		foreach ($pairs as $pair) {
			[$key] = $pair;
			if (! self::key_matches($key, $preserved) && self::key_matches($key, $patterns)) {
				$pulled[] = $pair;
			} else {
				$remaining[] = $pair;
			}
		}
		return [$pulled, $remaining];
	}

	/**
	 * Pull any pairs whose VALUE is the PANTHEON_STRIPPED literal.
	 * Public for testability.
	 */
	public static function pull_pantheon_stripped(array $pairs, array $preserved): array {
		$pulled    = [];
		$remaining = [];
		foreach ($pairs as $pair) {
			[$key, $value] = $pair;
			if ($value === self::PANTHEON_STRIPPED_VALUE && ! self::key_matches($key, $preserved)) {
				$pulled[] = $pair;
			} else {
				$remaining[] = $pair;
			}
		}
		return [$pulled, $remaining];
	}

	/**
	 * 301 to canonical URL built from the remaining pairs.
	 * No WP functions available here (pluggable.php not loaded yet) — raw header.
	 */
	protected static function redirect_to_canonical(array $remaining_pairs): void {
		$path  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
		$query = self::pairs_to_query($remaining_pairs);
		$location = $path . ($query === '' ? '' : '?' . $query);

		// Cacheable redirect. The strip decision is path-stable (we always
		// strip fbclid/srsltid/etc.), so a long TTL is safe and lets Varnish
		// serve subsequent unique tracking-param URLs without touching PHP.
		header('Cache-Control: public, max-age=86400');
		header('Location: ' . $location, true, 301);
		exit;
	}

	/**
	 * Rewrite $_GET, $_REQUEST, and $_SERVER['REQUEST_URI'] in place.
	 * The browser still has the dirty URL; the application sees clean.
	 */
	protected static function rewrite_request(array $remaining_pairs): void {
		$path  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
		$query = self::pairs_to_query($remaining_pairs);

		$_SERVER['QUERY_STRING'] = $query;
		$_SERVER['REQUEST_URI']  = $path . ($query === '' ? '' : '?' . $query);

		// Re-parse $_GET so any code reading it after this point sees the clean state.
		$parsed = [];
		if ($query !== '') {
			parse_str($query, $parsed);
		}
		$_GET = $parsed;

		// $_REQUEST blends $_GET + $_POST + $_COOKIE. Reconstruct conservatively.
		$_REQUEST = array_merge($_COOKIE ?? [], $_POST ?? [], $parsed);
	}

	/**
	 * Rebuild a query string from pairs. Preserves original encoding when possible
	 * (the third pair element is the raw chunk). Public for testability.
	 */
	public static function pairs_to_query(array $pairs): string {
		$chunks = [];
		foreach ($pairs as $pair) {
			$chunks[] = $pair[2] ?? (rawurlencode($pair[0]) . '=' . rawurlencode($pair[1]));
		}
		return implode('&', $chunks);
	}
}
