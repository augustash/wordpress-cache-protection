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
