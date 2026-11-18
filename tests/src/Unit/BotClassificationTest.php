<?php

declare(strict_types=1);

namespace Drupal\Tests\shortlink_manager\Unit;

use Drupal\shortlink_manager\ShortlinkClickTracker;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests bot classification of shortlink clicks.
 *
 * @group shortlink_manager
 */
#[Group('shortlink_manager')]
#[CoversMethod(ShortlinkClickTracker::class, 'matchesBot')]
final class BotClassificationTest extends UnitTestCase {

  /**
   * Data provider for testMatchesBot().
   */
  public static function providerMatchesBot(): array {
    $chrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
      . '(KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
    $safari = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) '
      . 'AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';
    return [
      'browser GET' => ['GET', $chrome, FALSE],
      'browser HEAD' => ['HEAD', $chrome, TRUE],
      'lowercase head' => ['head', $chrome, TRUE],
      'empty user agent' => ['GET', '', TRUE],
      'whitespace user agent' => ['GET', '   ', TRUE],
      'null user agent' => ['GET', NULL, TRUE],
      'facebook' => ['GET', 'facebookexternalhit/1.1', TRUE],
      'linkedin' => ['GET', 'LinkedInBot/1.0 (compatible; Mozilla/5.0)', TRUE],
      'node got client' => ['GET', 'got (https://github.com/sindresorhus/got)', TRUE],
      'mastodon server' => ['GET', 'http.rb/5.1.1 (Mastodon/4.3.0; +https://mastodon.social/)', TRUE],
      'misskey summaly' => ['GET', 'SummalyBot/5.1.0', TRUE],
      'bluesky cardyb' => ['GET', 'Mozilla/5.0 (compatible; Bluesky Cardyb/1.1; +mailto:support@bsky.app)', TRUE],
      'case-insensitive generic' => ['GET', 'SomeCRAWLER/2.0', TRUE],
      'iphone safari' => ['GET', $safari, FALSE],
    ];
  }

  /**
   * Tests bot matching against the default patterns.
   */
  #[DataProvider('providerMatchesBot')]
  public function testMatchesBot(string $method, ?string $user_agent, bool $expected): void {
    $this->assertSame($expected, ShortlinkClickTracker::matchesBot($method, $user_agent, ShortlinkClickTracker::DEFAULT_BOT_PATTERNS));
  }

  /**
   * Tests that blank patterns never match.
   */
  public function testEmptyPatternsAreIgnored(): void {
    $this->assertFalse(ShortlinkClickTracker::matchesBot('GET', 'Mozilla/5.0', ['', '  ']));
  }

}
