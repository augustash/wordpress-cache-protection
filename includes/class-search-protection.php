<?php
/**
 * Search protection: rate-limit search requests + prevent caching of search
 * responses.
 *
 * WordPress equivalent of drupal_cache_protection_search. Every search query
 * is unique, so the page cache can never help; meanwhile bots blast random
 * queries to fragment cache and overload the DB/search index. This module
 * throttles per-IP bursts and tells Varnish to never cache search responses.
 *
 * Mirrors the Drupal submodule's behavior:
 *   - Two flood windows (burst + sustained), either limit triggers 429
 *   - Page-cache kill switch on search responses
 *   - Only acts when a configured search query parameter is present, so the
 *     empty search form stays cacheable
 *
 * @package Augustash\WordpressCacheProtection
 */

namespace Augustash\WordpressCacheProtection;

if (! defined('ABSPATH')) {
	exit;
}

class SearchProtection {

	/** Default param names that mark a request as a search. */
	const DEFAULT_SEARCH_PARAMS = ['s'];

	/** Default flood windows. Burst = short spike, sustained = longer rate. */
	const DEFAULT_BURST_WINDOW    = 10; // seconds
	const DEFAULT_BURST_THRESHOLD = 5;  // requests per burst window
	const DEFAULT_SUSTAINED_WINDOW    = 60;
	const DEFAULT_SUSTAINED_THRESHOLD = 30;

	/** Option keys for admin-stored config. */
	const OPTION_ENABLED            = 'wpcp_search_protection_enabled';
	const OPTION_PARAMS             = 'wpcp_search_params';
	const OPTION_BURST_WINDOW       = 'wpcp_search_burst_window';
	const OPTION_BURST_THRESHOLD    = 'wpcp_search_burst_threshold';
	const OPTION_SUSTAINED_WINDOW   = 'wpcp_search_sustained_window';
	const OPTION_SUSTAINED_THRESHOLD = 'wpcp_search_sustained_threshold';

	public static function boot(): void {
		if (! self::is_enabled()) {
			return;
		}
		if (self::is_admin_or_internal_request()) {
			return;
		}
		if (! self::is_search_request()) {
			return;
		}

		// Tell Varnish/CDN: never cache this. Must run LATE — Pantheon's
		// mu-plugin sets `Cache-Control: public, max-age=302400` at
		// send_headers (default priority 10) and would otherwise overwrite
		// ours. Priority 999 fires after Pantheon's. Setting the header
		// directly here at plugins_loaded gets clobbered before the response
		// leaves PHP, so we hook instead.
		add_action('send_headers', [self::class, 'emit_uncache_headers'], 999);

		// Rate-limit check. Both windows are evaluated; either trigger 429s.
		$client_ip = self::client_ip();
		if ($client_ip === '') {
			// Can't identify a client → can't rate-limit. Let request through.
			return;
		}

		if (self::flood_exceeded($client_ip)) {
			self::reject_with_429();
		}

		self::register_hit($client_ip);
	}

	// ============================================================
	// Detection
	// ============================================================

	/**
	 * Pull search-param names from option/filter, falling back to defaults.
	 * Public for testability.
	 */
	public static function search_params(): array {
		$stored = get_option(self::OPTION_PARAMS, null);
		$list   = is_array($stored) ? $stored : self::DEFAULT_SEARCH_PARAMS;
		return (array) apply_filters('wpcp_search_params', $list);
	}

	/**
	 * Detect search requests by query-param presence. Empty values count as
	 * "not a search" so the bare search form stays cacheable.
	 */
	protected static function is_search_request(): bool {
		$params = self::search_params();
		foreach ($params as $param) {
			$param = (string) $param;
			if ($param === '') {
				continue;
			}
			if (isset($_GET[$param]) && $_GET[$param] !== '') {
				return true;
			}
		}
		return false;
	}

	/**
	 * Skip rules — non-GET, admin, AJAX, REST, cron, login, xmlrpc.
	 */
	protected static function is_admin_or_internal_request(): bool {
		if (! isset($_SERVER['REQUEST_METHOD']) || strtoupper($_SERVER['REQUEST_METHOD']) !== 'GET') {
			return true;
		}

		$uri  = $_SERVER['REQUEST_URI'] ?? '';
		$path = $uri === '' ? '' : (parse_url($uri, PHP_URL_PATH) ?: '');

		if ($path === '' || $path[0] !== '/') {
			return true;
		}
		if (strpos($path, '/wp-admin/') === 0) {
			return true;
		}
		if (strpos($path, '/wp-json/') === 0) {
			return true;
		}
		if ($path === '/wp-login.php' || $path === '/wp-cron.php' || $path === '/xmlrpc.php') {
			return true;
		}

		return false;
	}

	/**
	 * Whether the submodule is enabled. Defaults to ON because the protection
	 * is conservative — only kicks in on actual search requests.
	 */
	public static function is_enabled(): bool {
		$stored = get_option(self::OPTION_ENABLED, true);
		return (bool) apply_filters('wpcp_search_protection_enabled', $stored);
	}

	// ============================================================
	// Client IP
	// ============================================================

	/**
	 * Cloudflare's edge ranges, from https://www.cloudflare.com/ips/ (checked
	 * 2026-11-12). They change rarely; extend with `wpcp_trusted_proxies`.
	 */
	const CLOUDFLARE_RANGES = [
		'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
		'141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
		'197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
		'104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
		'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
		'2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
	];

	/**
	 * Resolve the visitor's IP. Public for testing.
	 *
	 * The connecting peer is Pantheon's client-IP header, else REMOTE_ADDR.
	 * Behind Cloudflare that peer is a Cloudflare edge shared by every visitor
	 * in a region, so when it is one, Cloudflare's own CF-Connecting-IP names
	 * the visitor. Cloudflare overwrites that header, so it can't be forged
	 * through the proxy; from anywhere else it is ignored.
	 *
	 * @param array<string,string>|null $server  Optional $_SERVER override (for tests).
	 * @param string[]|null             $proxies Optional trusted CIDRs (for tests).
	 */
	public static function client_ip(?array $server = null, ?array $proxies = null): string {
		$server  = $server ?? $_SERVER;
		$proxies = $proxies ?? self::trusted_proxies();

		$remote = self::valid_ip($server['REMOTE_ADDR'] ?? '');
		$peer   = self::valid_ip($server['HTTP_X_PANTHEON_CLIENT_IP'] ?? '') ?: $remote;

		$cf = self::valid_ip($server['HTTP_CF_CONNECTING_IP'] ?? '');
		if ($cf !== '' && (self::ip_in_ranges($peer, $proxies) || self::ip_in_ranges($remote, $proxies))) {
			return $cf;
		}
		return $peer;
	}

	/** @return string[] */
	public static function trusted_proxies(): array {
		$ranges = self::CLOUDFLARE_RANGES;
		return function_exists('apply_filters') ? (array) apply_filters('wpcp_trusted_proxies', $ranges) : $ranges;
	}

	protected static function valid_ip($value): string {
		$ip = trim((string) $value);
		return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
	}

	/**
	 * Whether an IP falls in any of the CIDR ranges (v4 or v6). Public for tests.
	 *
	 * @param string[] $ranges
	 */
	public static function ip_in_ranges(string $ip, array $ranges): bool {
		$addr = $ip === '' ? false : @inet_pton($ip);
		if ($addr === false) {
			return false;
		}
		foreach ($ranges as $range) {
			[$net, $bits] = array_pad(explode('/', (string) $range, 2), 2, null);
			$net = @inet_pton((string) $net);
			if ($net === false || strlen($net) !== strlen($addr)) {
				continue;
			}
			$bits  = $bits === null ? strlen($net) * 8 : (int) $bits;
			$bytes = intdiv($bits, 8);
			if (substr($addr, 0, $bytes) !== substr($net, 0, $bytes)) {
				continue;
			}
			$rest = $bits % 8;
			if ($rest === 0) {
				return true;
			}
			$mask = chr((0xff << (8 - $rest)) & 0xff);
			if ((($addr[$bytes] & $mask) === ($net[$bytes] & $mask))) {
				return true;
			}
		}
		return false;
	}

	// ============================================================
	// Rate limit
	// ============================================================

	/**
	 * Check both burst and sustained windows. Returns true if either exceeded.
	 */
	protected static function flood_exceeded(string $client_ip): bool {
		[$burst_window, $burst_threshold]         = self::burst_config();
		[$sustained_window, $sustained_threshold] = self::sustained_config();

		$burst_count     = self::current_count($client_ip, $burst_window);
		$sustained_count = self::current_count($client_ip, $sustained_window);

		return ($burst_count >= $burst_threshold) || ($sustained_count >= $sustained_threshold);
	}

	/**
	 * Increment counters for both windows. Called after a passing flood check
	 * so this request itself counts toward the next request's check.
	 */
	protected static function register_hit(string $client_ip): void {
		[$burst_window]     = self::burst_config();
		[$sustained_window] = self::sustained_config();

		self::increment_count($client_ip, $burst_window);
		self::increment_count($client_ip, $sustained_window);
	}

	/**
	 * Whether a count is over a threshold. Pure, public for tests.
	 */
	public static function threshold_exceeded(int $count, int $threshold): bool {
		return $count >= $threshold;
	}

	protected static function counter_key(string $client_ip, int $window): string {
		// Bucket key by window-aligned time slot — simple fixed-window scheme.
		// (Drupal's flood does the same; close enough to sliding for abuse purposes.)
		$bucket = (int) (time() / max(1, $window));
		return 'wpcp_s_' . md5($client_ip . '|' . $window . '|' . $bucket);
	}

	protected static function current_count(string $client_ip, int $window): int {
		$value = get_transient(self::counter_key($client_ip, $window));
		return is_numeric($value) ? (int) $value : 0;
	}

	protected static function increment_count(string $client_ip, int $window): void {
		$key   = self::counter_key($client_ip, $window);
		$value = (int) get_transient($key);
		set_transient($key, $value + 1, $window);
	}

	// ============================================================
	// Config accessors
	// ============================================================

	/** @return array{0:int,1:int} [window, threshold] */
	public static function burst_config(): array {
		$window    = (int) get_option(self::OPTION_BURST_WINDOW, self::DEFAULT_BURST_WINDOW);
		$threshold = (int) get_option(self::OPTION_BURST_THRESHOLD, self::DEFAULT_BURST_THRESHOLD);
		return [max(1, $window), max(1, $threshold)];
	}

	/** @return array{0:int,1:int} [window, threshold] */
	public static function sustained_config(): array {
		$window    = (int) get_option(self::OPTION_SUSTAINED_WINDOW, self::DEFAULT_SUSTAINED_WINDOW);
		$threshold = (int) get_option(self::OPTION_SUSTAINED_THRESHOLD, self::DEFAULT_SUSTAINED_THRESHOLD);
		return [max(1, $window), max(1, $threshold)];
	}

	// ============================================================
	// Response emission
	// ============================================================

	/**
	 * Public so it's callable as a `send_headers` action callback.
	 * Always idempotent — safe to call repeatedly.
	 */
	public static function emit_uncache_headers(): void {
		// Defensive: don't send headers after output (would be a no-op + notice).
		if (headers_sent()) {
			return;
		}
		header('Cache-Control: private, no-store, no-cache, max-age=0', true);
		header('Pragma: no-cache', true);
	}

	protected static function reject_with_429(): void {
		if (! headers_sent()) {
			header('Cache-Control: private, no-store, no-cache, max-age=0', true);
			header('Retry-After: 60', true);
		}
		http_response_code(429);
		echo "Too many search requests. Please try again shortly.\n";
		exit;
	}
}
