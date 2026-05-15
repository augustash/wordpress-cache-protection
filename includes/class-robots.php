<?php
/**
 * Robots.txt augmentation.
 *
 * Drupal module ships `assets/robots-additions.txt` and appends it via the
 * Composer scaffold mechanism. WordPress has a native `robots_txt` filter
 * that plugins (e.g. Yoast SEO) already use, so we append via the filter —
 * same source file, WP-native delivery, plays nicely with SEO plugins.
 *
 * @package Augustash\WordpressCacheProtection
 */

namespace Augustash\WordpressCacheProtection;

if (! defined('ABSPATH')) {
	exit;
}

class Robots {

	const ASSET_FILE = 'assets/robots-additions.txt';

	public static function boot(): void {
		add_filter('robots_txt', [self::class, 'append'], 20, 2);
	}

	/**
	 * Append the additions file. Runs at priority 20 so plugin-added blocks
	 * (Yoast at default 10) land first, our throttling rules below them.
	 */
	public static function append(string $output, $public): string {
		// Don't expose throttling rules on non-public installs (search engines
		// would be told to crawl, sandboxed env says no).
		if (! $public) {
			return $output;
		}

		$path = WPCP_PLUGIN_DIR . self::ASSET_FILE;
		if (! is_readable($path)) {
			return $output;
		}

		$additions = trim((string) file_get_contents($path));
		if ($additions === '') {
			return $output;
		}

		$additions = (string) apply_filters('wpcp_robots_additions', $additions);

		return rtrim($output, "\n") . "\n\n" . $additions . "\n";
	}
}
