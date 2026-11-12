<?php
/**
 * Unit tests for SearchwpIndexing's pure-logic seam.
 *
 * The SearchWP hooks themselves need a WordPress + SearchWP runtime and are
 * verified on a site; this covers which dropped ids get re-indexed.
 *
 * @package Augustash\WordpressCacheProtection\Tests\Unit
 */

declare(strict_types=1);

namespace Augustash\WordpressCacheProtection\Tests\Unit;

use Augustash\WordpressCacheProtection\SearchwpIndexing;
use PHPUnit\Framework\TestCase;

/**
 * @group aai
 * @group wpcp
 */
class SearchwpIndexingTest extends TestCase {

	public static function setUpBeforeClass(): void {
		require_once __DIR__ . '/../../includes/class-searchwp-indexing.php';
	}

	public function test_keeps_dropped_ids_searchwp_would_index(): void {
		$this->assertSame(['12'], SearchwpIndexing::eligible_ids(['12'], [3, 12, 40]));
	}

	public function test_leaves_out_ids_searchwp_would_not_index(): void {
		// A draft or trashed post is dropped on save but isn't unhandled.
		$this->assertSame([], SearchwpIndexing::eligible_ids(['99'], [3, 12]));
	}

	public function test_indexes_each_id_once_when_dropped_repeatedly(): void {
		// One save fires several drops for the same post (meta, terms, post).
		$this->assertSame(['12'], SearchwpIndexing::eligible_ids(['12', '12', 12], ['12']));
	}

	public function test_matches_int_and_string_ids(): void {
		$this->assertSame(['7', '8'], SearchwpIndexing::eligible_ids([7, '8'], ['7', 8]));
	}
}
