<?php
/**
 * Unit-test bootstrap. No WordPress required.
 *
 * Defines ABSPATH so the class files (which guard against direct load) can
 * be required, then loads the classes we want to test. Pure-logic unit tests
 * don't need apply_filters/get_option/etc., so we don't stub them — methods
 * that depend on WP runtime are integration-tested elsewhere.
 *
 * @package Augustash\WordpressCacheProtection\Tests
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	define('ABSPATH', __DIR__ . '/');
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/class-cache-protection.php';
