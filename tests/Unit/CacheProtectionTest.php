<?php
/**
 * Unit tests for CacheProtection's pure-logic methods.
 *
 * @package Augustash\WordpressCacheProtection\Tests\Unit
 */

declare(strict_types=1);

namespace Augustash\WordpressCacheProtection\Tests\Unit;

use Augustash\WordpressCacheProtection\CacheProtection;
use PHPUnit\Framework\TestCase;

/**
 * @group aai
 * @group wpcp
 */
class CacheProtectionTest extends TestCase {

	// ============================================================
	// parse_query_pairs()
	// ============================================================

	public function test_parse_query_pairs_returns_empty_array_for_empty_string(): void {
		$this->assertSame([], CacheProtection::parse_query_pairs(''));
	}

	public function test_parse_query_pairs_parses_single_pair(): void {
		$this->assertSame(
			[['foo', 'bar', 'foo=bar']],
			CacheProtection::parse_query_pairs('foo=bar')
		);
	}

	public function test_parse_query_pairs_parses_multiple_pairs_in_order(): void {
		$this->assertSame(
			[
				['a', '1', 'a=1'],
				['b', '2', 'b=2'],
				['c', '3', 'c=3'],
			],
			CacheProtection::parse_query_pairs('a=1&b=2&c=3')
		);
	}

	public function test_parse_query_pairs_handles_key_with_no_equals(): void {
		$this->assertSame(
			[['flag', '', 'flag']],
			CacheProtection::parse_query_pairs('flag')
		);
	}

	public function test_parse_query_pairs_handles_empty_value(): void {
		$this->assertSame(
			[['key', '', 'key=']],
			CacheProtection::parse_query_pairs('key=')
		);
	}

	public function test_parse_query_pairs_decodes_urlencoded_value(): void {
		$pairs = CacheProtection::parse_query_pairs('q=hello%20world');
		$this->assertSame(['q', 'hello world', 'q=hello%20world'], $pairs[0]);
	}

	public function test_parse_query_pairs_decodes_urlencoded_key(): void {
		$pairs = CacheProtection::parse_query_pairs('foo%5Bbar%5D=x');
		$this->assertSame(['foo[bar]', 'x', 'foo%5Bbar%5D=x'], $pairs[0]);
	}

	public function test_parse_query_pairs_preserves_repeated_keys(): void {
		// Drupal facets and WP nested arrays both rely on repeated keys.
		$this->assertSame(
			[
				['f[]', 'a', 'f%5B%5D=a'],
				['f[]', 'b', 'f%5B%5D=b'],
			],
			CacheProtection::parse_query_pairs('f%5B%5D=a&f%5B%5D=b')
		);
	}

	public function test_parse_query_pairs_skips_empty_chunks_from_consecutive_ampersands(): void {
		$this->assertSame(
			[
				['a', '1', 'a=1'],
				['b', '2', 'b=2'],
			],
			CacheProtection::parse_query_pairs('a=1&&b=2')
		);
	}

	public function test_parse_query_pairs_preserves_raw_chunk_for_round_trip(): void {
		// Third element keeps the original wire encoding so pairs_to_query
		// can reconstruct the exact bytes without re-encoding ambiguity.
		$pairs = CacheProtection::parse_query_pairs('q=hello+world');
		$this->assertSame('q=hello+world', $pairs[0][2]);
	}

	// ============================================================
	// key_matches()
	// ============================================================

	public function test_key_matches_exact(): void {
		$this->assertTrue(CacheProtection::key_matches('gclid', ['gclid']));
	}

	public function test_key_matches_returns_false_on_no_match(): void {
		$this->assertFalse(CacheProtection::key_matches('foo', ['gclid', 'fbclid']));
	}

	public function test_key_matches_wildcard_prefix(): void {
		$this->assertTrue(CacheProtection::key_matches('utm_source', ['utm_*']));
		$this->assertTrue(CacheProtection::key_matches('utm_medium', ['utm_*']));
		$this->assertTrue(CacheProtection::key_matches('utm_', ['utm_*']));
	}

	public function test_key_matches_wildcard_does_not_match_unrelated(): void {
		$this->assertFalse(CacheProtection::key_matches('source', ['utm_*']));
		$this->assertFalse(CacheProtection::key_matches('utmx', ['utm_*']));
	}

	public function test_key_matches_returns_false_for_empty_patterns(): void {
		$this->assertFalse(CacheProtection::key_matches('anything', []));
	}

	public function test_key_matches_skips_empty_pattern_strings(): void {
		$this->assertFalse(CacheProtection::key_matches('foo', ['', '   ']));
	}

	public function test_key_matches_bare_asterisk_does_not_match_everything(): void {
		// A pattern of just '*' would imply matching every key — that's almost
		// always a config mistake, so the prefix-must-be-nonempty guard
		// protects against accidentally wiping the entire query string.
		$this->assertFalse(CacheProtection::key_matches('anything', ['*']));
	}

	public function test_key_matches_finds_match_in_middle_of_list(): void {
		$this->assertTrue(CacheProtection::key_matches('fbclid', ['gclid', 'fbclid', 'msclkid']));
	}

	public function test_key_matches_is_case_sensitive(): void {
		// Query param case differences in the wild are usually different params.
		$this->assertFalse(CacheProtection::key_matches('GCLID', ['gclid']));
	}

	// ============================================================
	// pull_matching()
	// ============================================================

	public function test_pull_matching_returns_unchanged_when_no_patterns(): void {
		$pairs = [['a', '1', 'a=1'], ['b', '2', 'b=2']];
		[$pulled, $remaining] = CacheProtection::pull_matching($pairs, [], []);
		$this->assertSame([], $pulled);
		$this->assertSame($pairs, $remaining);
	}

	public function test_pull_matching_separates_matching_pairs(): void {
		$pairs = [
			['gclid', 'abc', 'gclid=abc'],
			['s', 'horse', 's=horse'],
			['fbclid', 'xyz', 'fbclid=xyz'],
		];
		[$pulled, $remaining] = CacheProtection::pull_matching($pairs, ['gclid', 'fbclid'], []);
		$this->assertSame(
			[
				['gclid', 'abc', 'gclid=abc'],
				['fbclid', 'xyz', 'fbclid=xyz'],
			],
			$pulled
		);
		$this->assertSame([['s', 'horse', 's=horse']], $remaining);
	}

	public function test_pull_matching_preserves_input_order_in_both_buckets(): void {
		$pairs = [
			['z', '1', 'z=1'],
			['gclid', 'a', 'gclid=a'],
			['a', '2', 'a=2'],
			['fbclid', 'b', 'fbclid=b'],
			['m', '3', 'm=3'],
		];
		[$pulled, $remaining] = CacheProtection::pull_matching($pairs, ['gclid', 'fbclid'], []);
		$this->assertSame(['gclid', 'fbclid'], array_column($pulled, 0));
		$this->assertSame(['z', 'a', 'm'], array_column($remaining, 0));
	}

	public function test_pull_matching_respects_preserve_list(): void {
		// A site can mark a param as "never strip even though it matches" —
		// e.g. their analytics consents and they want gclid to flow through.
		$pairs = [['gclid', 'abc', 'gclid=abc']];
		[$pulled, $remaining] = CacheProtection::pull_matching($pairs, ['gclid'], ['gclid']);
		$this->assertSame([], $pulled);
		$this->assertSame($pairs, $remaining);
	}

	public function test_pull_matching_supports_wildcards(): void {
		$pairs = [
			['utm_source', 'fb', 'utm_source=fb'],
			['utm_medium', 'cpc', 'utm_medium=cpc'],
			['s', 'horse', 's=horse'],
		];
		[$pulled, $remaining] = CacheProtection::pull_matching($pairs, ['utm_*'], []);
		$this->assertCount(2, $pulled);
		$this->assertSame([['s', 'horse', 's=horse']], $remaining);
	}

	public function test_pull_matching_preserve_list_supports_wildcards(): void {
		$pairs = [
			['utm_source', 'fb', 'utm_source=fb'],
			['gclid', 'x', 'gclid=x'],
		];
		[$pulled, $remaining] = CacheProtection::pull_matching($pairs, ['utm_*', 'gclid'], ['utm_*']);
		$this->assertSame([['gclid', 'x', 'gclid=x']], $pulled);
		$this->assertSame([['utm_source', 'fb', 'utm_source=fb']], $remaining);
	}

	// ============================================================
	// pull_pantheon_stripped()
	// ============================================================

	public function test_pull_pantheon_stripped_pulls_only_literal_value(): void {
		$pairs = [
			['utm_source', 'PANTHEON_STRIPPED', 'utm_source=PANTHEON_STRIPPED'],
			['utm_medium', 'cpc', 'utm_medium=cpc'],
		];
		[$pulled, $remaining] = CacheProtection::pull_pantheon_stripped($pairs, []);
		$this->assertSame([['utm_source', 'PANTHEON_STRIPPED', 'utm_source=PANTHEON_STRIPPED']], $pulled);
		$this->assertSame([['utm_medium', 'cpc', 'utm_medium=cpc']], $remaining);
	}

	public function test_pull_pantheon_stripped_ignores_similar_but_different_values(): void {
		$pairs = [
			['x', 'PANTHEON_STRIPPED_FOO', 'x=PANTHEON_STRIPPED_FOO'],
			['y', 'pantheon_stripped', 'y=pantheon_stripped'],
			['z', ' PANTHEON_STRIPPED', 'z=%20PANTHEON_STRIPPED'],
		];
		[$pulled, $remaining] = CacheProtection::pull_pantheon_stripped($pairs, []);
		$this->assertSame([], $pulled);
		$this->assertSame($pairs, $remaining);
	}

	public function test_pull_pantheon_stripped_respects_preserve_list(): void {
		$pairs = [['utm_source', 'PANTHEON_STRIPPED', 'utm_source=PANTHEON_STRIPPED']];
		[$pulled, $remaining] = CacheProtection::pull_pantheon_stripped($pairs, ['utm_source']);
		$this->assertSame([], $pulled);
		$this->assertSame($pairs, $remaining);
	}

	public function test_pull_pantheon_stripped_handles_empty_input(): void {
		[$pulled, $remaining] = CacheProtection::pull_pantheon_stripped([], []);
		$this->assertSame([], $pulled);
		$this->assertSame([], $remaining);
	}

	// ============================================================
	// pairs_to_query()
	// ============================================================

	public function test_pairs_to_query_empty(): void {
		$this->assertSame('', CacheProtection::pairs_to_query([]));
	}

	public function test_pairs_to_query_round_trips_through_parse(): void {
		// The most important guarantee — pairs_to_query(parse_query_pairs(X)) === X
		// for any well-formed query string. This is what keeps unmatched
		// params byte-identical when we rewrite or build a redirect.
		$cases = [
			'a=1',
			'a=1&b=2',
			'q=hello%20world',
			'f%5B%5D=x&f%5B%5D=y',
			'flag&q=value',
			'key=',
		];
		foreach ($cases as $qs) {
			$pairs = CacheProtection::parse_query_pairs($qs);
			$this->assertSame($qs, CacheProtection::pairs_to_query($pairs), "round-trip failed for: $qs");
		}
	}

	public function test_pairs_to_query_falls_back_to_encoding_when_raw_chunk_missing(): void {
		// If callers build pairs without a raw chunk, we should still produce
		// a valid query string. (Defensive — internal code always sets [2].)
		$pairs = [['hello world', 'foo bar']];
		$this->assertSame('hello%20world=foo%20bar', CacheProtection::pairs_to_query($pairs));
	}

	public function test_pairs_to_query_joins_with_ampersand(): void {
		$pairs = [
			['a', '1', 'a=1'],
			['b', '2', 'b=2'],
		];
		$this->assertSame('a=1&b=2', CacheProtection::pairs_to_query($pairs));
	}
}
