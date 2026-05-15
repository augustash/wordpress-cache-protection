<?php
/**
 * Unit tests for SearchProtection's pure-logic methods.
 *
 * Methods that use WP runtime (get_option / get_transient / apply_filters)
 * are integration-tested separately. These tests focus on string/IP/threshold
 * logic that can be exercised without a WordPress bootstrap.
 *
 * @package Augustash\WordpressCacheProtection\Tests\Unit
 */

declare(strict_types=1);

namespace Augustash\WordpressCacheProtection\Tests\Unit;

use Augustash\WordpressCacheProtection\SearchProtection;
use PHPUnit\Framework\TestCase;

/**
 * @group aai
 * @group wpcp
 */
class SearchProtectionTest extends TestCase {

	/**
	 * Make sure the class is autoloadable in the unit context.
	 * SearchProtection isn't required by bootstrap.php (only CacheProtection
	 * is), so include it here.
	 */
	public static function setUpBeforeClass(): void {
		require_once __DIR__ . '/../../includes/class-search-protection.php';
	}

	// ============================================================
	// first_xff()
	// ============================================================

	public function test_first_xff_returns_empty_for_empty_input(): void {
		$this->assertSame('', SearchProtection::first_xff(''));
	}

	public function test_first_xff_returns_only_ip_when_single(): void {
		$this->assertSame('203.0.113.5', SearchProtection::first_xff('203.0.113.5'));
	}

	public function test_first_xff_returns_first_in_chain(): void {
		// XFF is "client, proxy1, proxy2" — leftmost is the original client.
		$this->assertSame(
			'203.0.113.5',
			SearchProtection::first_xff('203.0.113.5, 10.0.0.1, 10.0.0.2')
		);
	}

	public function test_first_xff_trims_whitespace(): void {
		$this->assertSame('203.0.113.5', SearchProtection::first_xff('  203.0.113.5  , 10.0.0.1'));
	}

	public function test_first_xff_handles_ipv6(): void {
		$this->assertSame(
			'2603:7081:f00:e2d:8110:b9fb:6ceb:ae75',
			SearchProtection::first_xff('2603:7081:f00:e2d:8110:b9fb:6ceb:ae75, 10.1.4.22')
		);
	}

	// ============================================================
	// client_ip()
	// ============================================================

	public function test_client_ip_prefers_pantheon_header(): void {
		$server = [
			'HTTP_X_PANTHEON_CLIENT_IP' => '203.0.113.5',
			'HTTP_CF_CONNECTING_IP'     => '198.51.100.7',
			'HTTP_X_FORWARDED_FOR'      => '192.0.2.1, 10.0.0.1',
			'REMOTE_ADDR'               => '10.0.0.99',
		];
		$this->assertSame('203.0.113.5', SearchProtection::client_ip($server));
	}

	public function test_client_ip_falls_back_to_cloudflare_when_no_pantheon(): void {
		$server = [
			'HTTP_CF_CONNECTING_IP' => '198.51.100.7',
			'HTTP_X_FORWARDED_FOR'  => '192.0.2.1',
			'REMOTE_ADDR'           => '10.0.0.99',
		];
		$this->assertSame('198.51.100.7', SearchProtection::client_ip($server));
	}

	public function test_client_ip_falls_back_to_xff_first_ip(): void {
		$server = [
			'HTTP_X_FORWARDED_FOR' => '192.0.2.1, 10.0.0.1, 10.0.0.2',
			'REMOTE_ADDR'          => '10.0.0.99',
		];
		$this->assertSame('192.0.2.1', SearchProtection::client_ip($server));
	}

	public function test_client_ip_falls_back_to_remote_addr(): void {
		$server = ['REMOTE_ADDR' => '10.0.0.99'];
		$this->assertSame('10.0.0.99', SearchProtection::client_ip($server));
	}

	public function test_client_ip_returns_empty_when_nothing_present(): void {
		$this->assertSame('', SearchProtection::client_ip([]));
	}

	public function test_client_ip_skips_invalid_values(): void {
		// A spoofed header containing garbage shouldn't poison the result.
		$server = [
			'HTTP_X_PANTHEON_CLIENT_IP' => 'not-an-ip',
			'HTTP_X_FORWARDED_FOR'      => '192.0.2.1',
			'REMOTE_ADDR'               => '10.0.0.99',
		];
		$this->assertSame('192.0.2.1', SearchProtection::client_ip($server));
	}

	public function test_client_ip_accepts_ipv6(): void {
		$server = ['HTTP_X_PANTHEON_CLIENT_IP' => '2603:7081:f00:e2d::1'];
		$this->assertSame('2603:7081:f00:e2d::1', SearchProtection::client_ip($server));
	}

	// ============================================================
	// threshold_exceeded()
	// ============================================================

	public function test_threshold_not_exceeded_below_limit(): void {
		$this->assertFalse(SearchProtection::threshold_exceeded(0, 5));
		$this->assertFalse(SearchProtection::threshold_exceeded(4, 5));
	}

	public function test_threshold_exceeded_at_or_above_limit(): void {
		// At-threshold counts as exceeded — this is what makes "threshold = 5"
		// mean "max 5 successful requests" (the 6th is rejected when count is
		// already 5 from the prior 5 requests).
		$this->assertTrue(SearchProtection::threshold_exceeded(5, 5));
		$this->assertTrue(SearchProtection::threshold_exceeded(100, 5));
	}

	public function test_threshold_with_zero_threshold_blocks_immediately(): void {
		// Defensive: a misconfigured threshold of 0 should reject all requests
		// rather than allow them — fail closed.
		$this->assertTrue(SearchProtection::threshold_exceeded(0, 0));
	}

	// ============================================================
	// search_params() filter integration smoke-check
	// ============================================================
	// search_params() uses get_option + apply_filters which aren't available
	// without WP. We skip those tests here and rely on integration tests
	// (live DDEV smoke) to verify config wiring.
}
