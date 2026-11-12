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
	// client_ip()
	// ============================================================

	/** A Cloudflare edge address, as Pantheon sees it on a proxied site. */
	const EDGE = '104.22.102.65';

	public function test_client_ip_uses_cloudflare_header_when_peer_is_cloudflare(): void {
		// Behind Cloudflare the peer is the edge; everyone in a region shares it.
		$server = [
			'HTTP_X_PANTHEON_CLIENT_IP' => self::EDGE,
			'HTTP_CF_CONNECTING_IP'     => '203.0.113.5',
			'REMOTE_ADDR'               => '10.1.8.137',
		];
		$this->assertSame('203.0.113.5', SearchProtection::client_ip($server));
	}

	public function test_client_ip_uses_cloudflare_header_when_remote_addr_is_cloudflare(): void {
		$server = ['HTTP_CF_CONNECTING_IP' => '203.0.113.5', 'REMOTE_ADDR' => self::EDGE];
		$this->assertSame('203.0.113.5', SearchProtection::client_ip($server));
	}

	public function test_client_ip_ignores_cloudflare_header_from_anyone_else(): void {
		// Straight to the origin with a forged header: rotating it must not dodge the limit.
		$server = [
			'HTTP_X_PANTHEON_CLIENT_IP' => '198.51.100.7',
			'HTTP_CF_CONNECTING_IP'     => '203.0.113.5',
			'REMOTE_ADDR'               => '10.1.8.137',
		];
		$this->assertSame('198.51.100.7', SearchProtection::client_ip($server));
	}

	public function test_client_ip_never_trusts_forwarded_for(): void {
		$server = ['HTTP_X_FORWARDED_FOR' => '192.0.2.1', 'REMOTE_ADDR' => '198.51.100.7'];
		$this->assertSame('198.51.100.7', SearchProtection::client_ip($server));
	}

	public function test_client_ip_prefers_pantheon_header_over_remote_addr(): void {
		$server = ['HTTP_X_PANTHEON_CLIENT_IP' => '203.0.113.5', 'REMOTE_ADDR' => '10.0.0.99'];
		$this->assertSame('203.0.113.5', SearchProtection::client_ip($server));
	}

	public function test_client_ip_falls_back_to_remote_addr(): void {
		$this->assertSame('10.0.0.99', SearchProtection::client_ip(['REMOTE_ADDR' => '10.0.0.99']));
	}

	public function test_client_ip_returns_empty_when_nothing_present(): void {
		$this->assertSame('', SearchProtection::client_ip([]));
	}

	public function test_client_ip_skips_invalid_values(): void {
		$server = [
			'HTTP_X_PANTHEON_CLIENT_IP' => 'not-an-ip',
			'HTTP_CF_CONNECTING_IP'     => 'also-not',
			'REMOTE_ADDR'               => self::EDGE,
		];
		$this->assertSame(self::EDGE, SearchProtection::client_ip($server));
	}

	public function test_client_ip_handles_ipv6_edge_and_visitor(): void {
		$server = [
			'HTTP_X_PANTHEON_CLIENT_IP' => '2606:4700:10::ac43:1',
			'HTTP_CF_CONNECTING_IP'     => '2603:7081:f00:e2d::1',
		];
		$this->assertSame('2603:7081:f00:e2d::1', SearchProtection::client_ip($server));
	}

	public function test_client_ip_honours_given_proxy_ranges(): void {
		$server = ['HTTP_CF_CONNECTING_IP' => '203.0.113.5', 'REMOTE_ADDR' => '10.0.0.99'];
		$this->assertSame('203.0.113.5', SearchProtection::client_ip($server, ['10.0.0.0/8']));
	}

	// ============================================================
	// ip_in_ranges()
	// ============================================================

	public function test_ip_in_ranges_matches_on_partial_byte_boundary(): void {
		// 162.158.0.0/15 covers 162.158.* and 162.159.*, not 162.160.*.
		$this->assertTrue(SearchProtection::ip_in_ranges('162.159.255.1', ['162.158.0.0/15']));
		$this->assertFalse(SearchProtection::ip_in_ranges('162.160.0.1', ['162.158.0.0/15']));
	}

	public function test_ip_in_ranges_does_not_cross_families(): void {
		$this->assertFalse(SearchProtection::ip_in_ranges('::ffff:104.16.0.1', ['104.16.0.0/13']));
		$this->assertFalse(SearchProtection::ip_in_ranges('104.16.0.1', ['2606:4700::/32']));
	}

	public function test_ip_in_ranges_rejects_empty_and_garbage(): void {
		$this->assertFalse(SearchProtection::ip_in_ranges('', ['0.0.0.0/0']));
		$this->assertFalse(SearchProtection::ip_in_ranges('nope', ['0.0.0.0/0']));
	}

	public function test_ip_in_ranges_zero_prefix_matches_everything_in_family(): void {
		$this->assertTrue(SearchProtection::ip_in_ranges('8.8.8.8', ['0.0.0.0/0']));
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
