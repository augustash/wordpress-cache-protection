<?php
/**
 * SearchWP indexing without HTTP self-calls.
 *
 * Out of the box SearchWP 4 indexes by POSTing to the site's own admin-ajax
 * URL, one batch per request. Each batch holds a PHP-FPM worker while it runs,
 * and the request that dispatched it often holds a second one waiting. On
 * Pantheon the call also leaves by the public hostname, so behind Cloudflare
 * it goes out and back in. On a small plan (Basic has 4 workers) one indexing
 * run is half the pool, and visitors queue behind it every time staff edit.
 *
 * Instead:
 *   - Web requests never start SearchWP's background process.
 *   - A save drops the entry from the index at once (aggressive delta), and
 *     the same request re-indexes it after the response has gone out
 *     (fastcgi_finish_request): the worker it already holds, ~20ms longer.
 *   - An hourly WP-Cron event sweeps anything missed. On Pantheon WP-Cron runs
 *     through WP-CLI, outside the web pool, so the sweep costs no worker.
 *
 * Only acts when SearchWP 4+ is active. Toggle in Settings → Cache Protection.
 *
 * @package Augustash\WordpressCacheProtection
 */

namespace Augustash\WordpressCacheProtection;

if (! defined('ABSPATH')) {
	exit;
}

class SearchwpIndexing {

	const OPTION_ENABLED = 'wpcp_searchwp_indexing_enabled';
	const CRON_HOOK      = 'wpcp_searchwp_index';

	/** @var array<string, string[]> Entry ids this request dropped, by source name. */
	protected static $dropped = [];

	public static function boot(): void {
		if (! self::searchwp_active() || ! self::is_enabled()) {
			self::unschedule();
			return;
		}

		// SearchWP reads these when it builds its indexer at init priority
		// 99999, so registering them at plugins_loaded is in time.
		add_filter('searchwp\index\process\enabled', [self::class, 'in_cli']);
		// Skips the blocking HTTP self-test SearchWP runs on save to choose
		// how to reach itself; alternate mode never dispatches to continue.
		add_filter('searchwp\indexer\alternate', '__return_true');
		// Without this a save queues a delta, which `wp searchwp index`
		// destroys rather than processes, so edits would never re-index.
		add_filter('searchwp\index\aggressive_delta', '__return_true');

		add_action('searchwp\index\drop', [self::class, 'remember_drop']);
		add_action('shutdown', [self::class, 'index_after_response'], 100);

		add_action('init', [self::class, 'schedule']);
		add_action(self::CRON_HOOK, [self::class, 'sweep']);
	}

	public static function searchwp_active(): bool {
		return class_exists('\SearchWP')
			&& defined('SEARCHWP_VERSION')
			&& version_compare(SEARCHWP_VERSION, '4.0', '>=');
	}

	/**
	 * Defaults to ON: it only changes how SearchWP reaches its own index.
	 */
	public static function is_enabled(): bool {
		$stored = get_option(self::OPTION_ENABLED, true);
		return (bool) apply_filters('wpcp_searchwp_indexing_enabled', $stored);
	}

	public static function in_cli(): bool {
		return defined('WP_CLI') && WP_CLI;
	}

	/**
	 * Fires once per entry SearchWP drops. drop_post checks
	 * current_user_can('edit_post'), so a logged-out save never gets here.
	 *
	 * @param array $args SearchWP's drop args; 'entry' is a \SearchWP\Entry.
	 */
	public static function remember_drop($args): void {
		$entry = is_array($args) ? ($args['entry'] ?? null) : null;
		if ($entry instanceof \SearchWP\Entry) {
			self::$dropped[$entry->get_source()->get_name()][] = (string) $entry->get_id();
		}
	}

	public static function index_after_response(): void {
		if (! self::$dropped || self::in_cli()) {
			return;
		}
		$dropped       = self::$dropped;
		self::$dropped = [];

		if (function_exists('fastcgi_finish_request')) {
			fastcgi_finish_request();
		}
		self::index_entries($dropped);
	}

	/**
	 * Index entries the way `wp searchwp index` does: a status row, then the entry.
	 *
	 * @param array<string, string[]> $dropped Entry ids by source name.
	 */
	public static function index_entries(array $dropped): void {
		global $wpdb;
		$index = \SearchWP::$index;
		$table = $index->get_tables()['status']->table_name;

		foreach ($dropped as $source_name => $ids) {
			$source = $index->get_source_by_name($source_name);
			if (! $source) {
				continue;
			}
			foreach (self::eligible_ids($ids, $source->get_unhandled_ids(-1)) as $id) {
				// $index->add() only UPDATEs the status row; without one the
				// entry indexes but still reads as unhandled.
				$wpdb->insert($table, [
					'id'     => $id,
					'source' => $source_name,
					'queued' => current_time('mysql'),
					'site'   => get_current_blog_id(),
				]);
				$entry = apply_filters('searchwp\indexer\entry', new \SearchWP\Entry($source, $id));
				if ($entry instanceof \SearchWP\Entry && false === $index->add($entry)) {
					$index->mark_entry_as($entry, 'omitted');
				}
			}
		}
	}

	/**
	 * The dropped ids SearchWP would index now. A draft, trashed or excluded
	 * post isn't among the source's unhandled ids, so it stays out.
	 *
	 * @param array $dropped   Ids this request dropped (may repeat).
	 * @param array $unhandled The source's unhandled ids (ints or strings).
	 * @return string[]
	 */
	public static function eligible_ids(array $dropped, array $unhandled): array {
		$unhandled = array_map('strval', $unhandled);
		$dropped   = array_unique(array_map('strval', $dropped));
		return array_values(array_intersect($dropped, $unhandled));
	}

	public static function schedule(): void {
		if (! wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_event(time(), 'hourly', self::CRON_HOOK);
		}
	}

	public static function unschedule(): void {
		if (function_exists('wp_next_scheduled') && wp_next_scheduled(self::CRON_HOOK)) {
			wp_clear_scheduled_hook(self::CRON_HOOK);
		}
	}

	/**
	 * Under WP-CLI only: a sweep from a web request would be the very
	 * worker-holding run this class exists to avoid.
	 */
	public static function sweep(): void {
		if (self::in_cli() && class_exists('\SearchWP\CLI')) {
			\SearchWP\CLI::index();
		}
	}
}
