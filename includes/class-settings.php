<?php
/**
 * Admin settings page — Settings → Cache Protection.
 *
 * Mirrors the Drupal module's admin form: two textareas for redirect_params
 * and strip_params. Values stored as WP options, overridable via filters.
 *
 * @package Augustash\WordpressCacheProtection
 */

namespace Augustash\WordpressCacheProtection;

if (! defined('ABSPATH')) {
	exit;
}

class Settings {

	const OPTION_REDIRECT = 'wpcp_redirect_params_option';
	const OPTION_STRIP    = 'wpcp_strip_params_option';
	const PAGE_SLUG       = 'wordpress-cache-protection';
	const NONCE_ACTION    = 'wpcp_save_settings';

	public static function boot(): void {
		add_action('admin_menu', [self::class, 'register_menu']);
		add_action('admin_init', [self::class, 'maybe_save']);
	}

	public static function register_menu(): void {
		add_options_page(
			__('Cache Protection', 'wordpress-cache-protection'),
			__('Cache Protection', 'wordpress-cache-protection'),
			'manage_options',
			self::PAGE_SLUG,
			[self::class, 'render']
		);
	}

	public static function maybe_save(): void {
		if (! isset($_POST['wpcp_save'])) {
			return;
		}
		if (! current_user_can('manage_options')) {
			wp_die(__('Insufficient permissions.', 'wordpress-cache-protection'));
		}
		check_admin_referer(self::NONCE_ACTION);

		update_option(self::OPTION_REDIRECT, self::parse_list($_POST['redirect_params'] ?? ''));
		update_option(self::OPTION_STRIP, self::parse_list($_POST['strip_params'] ?? ''));

		// Search protection settings.
		update_option(SearchProtection::OPTION_ENABLED, ! empty($_POST['search_protection_enabled']));
		update_option(SearchProtection::OPTION_PARAMS, self::parse_list($_POST['search_params'] ?? ''));
		update_option(SearchProtection::OPTION_BURST_WINDOW, max(1, (int) ($_POST['search_burst_window'] ?? SearchProtection::DEFAULT_BURST_WINDOW)));
		update_option(SearchProtection::OPTION_BURST_THRESHOLD, max(1, (int) ($_POST['search_burst_threshold'] ?? SearchProtection::DEFAULT_BURST_THRESHOLD)));
		update_option(SearchProtection::OPTION_SUSTAINED_WINDOW, max(1, (int) ($_POST['search_sustained_window'] ?? SearchProtection::DEFAULT_SUSTAINED_WINDOW)));
		update_option(SearchProtection::OPTION_SUSTAINED_THRESHOLD, max(1, (int) ($_POST['search_sustained_threshold'] ?? SearchProtection::DEFAULT_SUSTAINED_THRESHOLD)));

		update_option(SearchwpIndexing::OPTION_ENABLED, ! empty($_POST['searchwp_indexing_enabled']));
		update_option(Robots::OPTION_THROTTLE_AHREFS, ! empty($_POST['robots_throttle_ahrefs']));

		add_settings_error(
			self::PAGE_SLUG,
			'wpcp_saved',
			__('Settings saved.', 'wordpress-cache-protection'),
			'updated'
		);
	}

	public static function render(): void {
		$redirect = get_option(self::OPTION_REDIRECT, CacheProtection::DEFAULT_REDIRECT_PARAMS);
		$strip    = get_option(self::OPTION_STRIP, CacheProtection::DEFAULT_STRIP_PARAMS);

		$effective_redirect = (array) apply_filters('wpcp_redirect_params', $redirect);
		$effective_strip    = (array) apply_filters('wpcp_strip_params', $strip);
		$effective_preserved = (array) apply_filters('wpcp_preserved_params', []);

		?>
		<div class="wrap">
			<h1><?php esc_html_e('Cache Protection', 'wordpress-cache-protection'); ?></h1>
			<?php settings_errors(self::PAGE_SLUG); ?>

			<p>
				<?php esc_html_e('Tracking-param handling so Varnish/page caches key on canonical URLs. One param name per line. Trailing asterisk allowed for prefix matching (e.g. utm_* matches utm_source, utm_medium…).', 'wordpress-cache-protection'); ?>
			</p>

			<form method="post" action="">
				<?php wp_nonce_field(self::NONCE_ACTION); ?>

				<h2><?php esc_html_e('Redirect params (301 → clean URL)', 'wordpress-cache-protection'); ?></h2>
				<p class="description">
					<?php esc_html_e('Params NOT read by on-site JS. The 301 is itself Varnish-cached → subsequent dirty hits cost no PHP.', 'wordpress-cache-protection'); ?>
				</p>
				<textarea name="redirect_params" rows="6" cols="40" class="large-text code"><?php echo esc_textarea(self::list_to_text($redirect)); ?></textarea>

				<h2><?php esc_html_e('Strip params (internal rewrite)', 'wordpress-cache-protection'); ?></h2>
				<p class="description">
					<?php esc_html_e('Params client-side JS reads from window.location for attribution. Browser URL stays dirty; the application sees clean.', 'wordpress-cache-protection'); ?>
				</p>
				<textarea name="strip_params" rows="10" cols="40" class="large-text code"><?php echo esc_textarea(self::list_to_text($strip)); ?></textarea>

				<hr>

				<h2><?php esc_html_e('Search protection', 'wordpress-cache-protection'); ?></h2>
				<p class="description">
					<?php esc_html_e('Rate-limit search requests per IP and tell Varnish to never cache search responses. Mirrors drupal_cache_protection_search.', 'wordpress-cache-protection'); ?>
				</p>

				<?php
				$search_enabled            = (bool) get_option(SearchProtection::OPTION_ENABLED, true);
				$search_params             = get_option(SearchProtection::OPTION_PARAMS, SearchProtection::DEFAULT_SEARCH_PARAMS);
				$search_params             = is_array($search_params) ? $search_params : SearchProtection::DEFAULT_SEARCH_PARAMS;
				$search_burst_window       = (int) get_option(SearchProtection::OPTION_BURST_WINDOW, SearchProtection::DEFAULT_BURST_WINDOW);
				$search_burst_threshold    = (int) get_option(SearchProtection::OPTION_BURST_THRESHOLD, SearchProtection::DEFAULT_BURST_THRESHOLD);
				$search_sustained_window   = (int) get_option(SearchProtection::OPTION_SUSTAINED_WINDOW, SearchProtection::DEFAULT_SUSTAINED_WINDOW);
				$search_sustained_threshold = (int) get_option(SearchProtection::OPTION_SUSTAINED_THRESHOLD, SearchProtection::DEFAULT_SUSTAINED_THRESHOLD);
				?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e('Enabled', 'wordpress-cache-protection'); ?></th>
						<td>
							<label>
								<input type="checkbox" name="search_protection_enabled" value="1" <?php checked($search_enabled); ?>>
								<?php esc_html_e('Throttle and uncache search requests', 'wordpress-cache-protection'); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Search params', 'wordpress-cache-protection'); ?></th>
						<td>
							<textarea name="search_params" rows="3" cols="20" class="code"><?php echo esc_textarea(self::list_to_text($search_params)); ?></textarea>
							<p class="description"><?php esc_html_e('One per line. Default: s (WP core search). Add others if a search plugin uses different param names.', 'wordpress-cache-protection'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Burst window', 'wordpress-cache-protection'); ?></th>
						<td>
							<input type="number" name="search_burst_window" min="1" value="<?php echo esc_attr((string) $search_burst_window); ?>" class="small-text">
							<?php esc_html_e('seconds', 'wordpress-cache-protection'); ?>
							/ max <input type="number" name="search_burst_threshold" min="1" value="<?php echo esc_attr((string) $search_burst_threshold); ?>" class="small-text">
							<?php esc_html_e('requests', 'wordpress-cache-protection'); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Sustained window', 'wordpress-cache-protection'); ?></th>
						<td>
							<input type="number" name="search_sustained_window" min="1" value="<?php echo esc_attr((string) $search_sustained_window); ?>" class="small-text">
							<?php esc_html_e('seconds', 'wordpress-cache-protection'); ?>
							/ max <input type="number" name="search_sustained_threshold" min="1" value="<?php echo esc_attr((string) $search_sustained_threshold); ?>" class="small-text">
							<?php esc_html_e('requests', 'wordpress-cache-protection'); ?>
						</td>
					</tr>
				</table>

				<hr>

				<h2><?php esc_html_e('SearchWP indexing', 'wordpress-cache-protection'); ?></h2>
				<p class="description">
					<?php esc_html_e('SearchWP indexes by calling the site on itself over HTTP, holding a PHP worker (often two) per batch. With this on, a save re-indexes its own entry after the response is sent, and an hourly WP-CLI cron sweeps the rest. SearchWP\'s settings screen will show the indexer as paused; that is expected.', 'wordpress-cache-protection'); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e('Enabled', 'wordpress-cache-protection'); ?></th>
						<td>
							<label>
								<input type="checkbox" name="searchwp_indexing_enabled" value="1" <?php checked((bool) get_option(SearchwpIndexing::OPTION_ENABLED, true)); ?>>
								<?php esc_html_e('Index without HTTP self-calls', 'wordpress-cache-protection'); ?>
							</label>
							<?php if (! SearchwpIndexing::searchwp_active()) : ?>
								<p class="description"><?php esc_html_e('SearchWP 4 is not active, so this does nothing here.', 'wordpress-cache-protection'); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<hr>

				<h2><?php esc_html_e('robots.txt', 'wordpress-cache-protection'); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e('Ahrefs site audits', 'wordpress-cache-protection'); ?></th>
						<td>
							<label>
								<input type="checkbox" name="robots_throttle_ahrefs" value="1" <?php checked(Robots::throttles_ahrefs()); ?>>
								<?php esc_html_e('Ask AhrefsSiteAudit to wait 60 seconds between requests', 'wordpress-cache-protection'); ?>
							</label>
							<p class="description"><?php esc_html_e('An audit has saturated PHP workers on other sites. Turn this off when this site\'s logs show Ahrefs metering itself: few requests at once and none landing alongside worker saturation.', 'wordpress-cache-protection'); ?></p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" name="wpcp_save" class="button button-primary"><?php esc_html_e('Save', 'wordpress-cache-protection'); ?></button>
				</p>
			</form>

			<h2><?php esc_html_e('Effective configuration (after filters)', 'wordpress-cache-protection'); ?></h2>
			<p class="description">
				<?php esc_html_e('What the request handler actually uses. Filters from theme/plugins override stored values.', 'wordpress-cache-protection'); ?>
			</p>
			<p><strong><?php esc_html_e('Redirect:', 'wordpress-cache-protection'); ?></strong> <code><?php echo esc_html(implode(', ', $effective_redirect) ?: '(none)'); ?></code></p>
			<p><strong><?php esc_html_e('Strip:', 'wordpress-cache-protection'); ?></strong> <code><?php echo esc_html(implode(', ', $effective_strip) ?: '(none)'); ?></code></p>
			<p><strong><?php esc_html_e('Preserved (never touched):', 'wordpress-cache-protection'); ?></strong> <code><?php echo esc_html(implode(', ', $effective_preserved) ?: '(none)'); ?></code></p>
		</div>
		<?php
	}

	/**
	 * Parse textarea content (one entry per line) into a clean array.
	 */
	protected static function parse_list(string $raw): array {
		$lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
		$out   = [];
		foreach ($lines as $line) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}
			$out[] = $line;
		}
		return array_values(array_unique($out));
	}

	protected static function list_to_text(array $list): string {
		return implode("\n", array_map('strval', $list));
	}
}
