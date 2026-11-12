<?php
/**
 * Unit tests for removing the Ahrefs throttle from the robots additions.
 *
 * @package Augustash\WordpressCacheProtection\Tests\Unit
 */

declare(strict_types=1);

namespace Augustash\WordpressCacheProtection\Tests\Unit;

use Augustash\WordpressCacheProtection\Robots;
use PHPUnit\Framework\TestCase;

/**
 * @group aai
 * @group wpcp
 */
class RobotsTest extends TestCase {

	private static string $shipped;

	public static function setUpBeforeClass(): void {
		require_once __DIR__ . '/../../includes/class-robots.php';
		self::$shipped = (string) file_get_contents(__DIR__ . '/../../assets/robots-additions.txt');
	}

	public function test_removes_the_ahrefs_block_from_the_shipped_file(): void {
		$out = Robots::without_ahrefs_throttle(self::$shipped);
		$this->assertStringNotContainsString('AhrefsSiteAudit', $out);
		$this->assertStringNotContainsString('Crawl-delay', $out);
	}

	public function test_keeps_every_other_rule(): void {
		// Against the real asset, so an edit to the file can't silently eat a neighbour.
		$out = Robots::without_ahrefs_throttle(self::$shipped);
		$this->assertStringContainsString('Disallow: /xmlrpc.php', $out);
		$this->assertStringContainsString("User-agent: IbouBot\nDisallow: /", $out);
		$this->assertMatchesRegularExpression('/User-agent: \*\s*$/', $out);
	}

	public function test_leaves_xmlrpc_rule_under_the_preceding_group(): void {
		// The Disallow has no User-agent of its own; it must still come before any group starts.
		$out = Robots::without_ahrefs_throttle(self::$shipped);
		$this->assertLessThan(strpos($out, 'User-agent:'), strpos($out, 'Disallow: /xmlrpc.php'));
	}
}
