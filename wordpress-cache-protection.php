<?php
/**
 * Plugin Name:       WordPress Cache Protection
 * Plugin URI:        https://github.com/augustash/wordpress-cache-protection
 * Description:       Strips/redirects tracking params so Varnish caches canonical URLs, augments robots.txt with bot-throttling rules. WordPress equivalent of drupal_cache_protection.
 * Version:           1.0.0-dev
 * Requires at least: 5.5
 * Requires PHP:      7.4
 * Author:            August Ash
 * Author URI:        https://augustash.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wordpress-cache-protection
 *
 * @package Augustash\WordpressCacheProtection
 */

if (! defined('ABSPATH')) {
	exit;
}

define('WPCP_VERSION', '1.0.0-dev');
define('WPCP_PLUGIN_FILE', __FILE__);
define('WPCP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPCP_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once WPCP_PLUGIN_DIR . 'includes/class-cache-protection.php';
require_once WPCP_PLUGIN_DIR . 'includes/class-search-protection.php';
require_once WPCP_PLUGIN_DIR . 'includes/class-settings.php';
require_once WPCP_PLUGIN_DIR . 'includes/class-robots.php';

// Strip/redirect runs as early as possible — at plugin load time, before
// muplugins_loaded fires, before WP queries posts, before page caches key on
// the URL. The Drupal middleware fires at priority 290; this is the WP
// equivalent of being early in the request pipeline.
\Augustash\WordpressCacheProtection\CacheProtection::boot();

// Search protection runs at plugins_loaded — it needs the options API and
// transient store to be available, which CacheProtection doesn't. Still
// early enough to set headers and short-circuit before WP runs the main query.
add_action('plugins_loaded', function () {
	\Augustash\WordpressCacheProtection\SearchProtection::boot();
	\Augustash\WordpressCacheProtection\Settings::boot();
	\Augustash\WordpressCacheProtection\Robots::boot();
}, 1);
