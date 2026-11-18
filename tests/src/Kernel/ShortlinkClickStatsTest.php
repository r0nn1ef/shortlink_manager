<?php

declare(strict_types=1);

namespace Drupal\Tests\shortlink_manager\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\KernelTests\KernelTestBase;
use Drupal\shortlink_manager\Controller\ShortlinkRedirectController;
use Drupal\shortlink_manager\Entity\Shortlink;
use Drupal\shortlink_manager\ShortlinkClickTracker;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests bot filtering, human/unique counts and daily click rollups.
 *
 * @group shortlink_manager
 */
#[Group('shortlink_manager')]
#[RunTestsInSeparateProcesses]
final class ShortlinkClickStatsTest extends KernelTestBase {

  private const BROWSER = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'path_alias',
    'views',
    'serialization',
    'rest',
    'token',
    'csv_serialization',
    'views_data_export',
    'shortlink_manager',
  ];

  /**
   * The click tracker.
   */
  private ShortlinkClickTracker $tracker;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('shortlink');
    $this->installSchema('shortlink_manager', ['shortlink_clicks', 'shortlink_clicks_daily']);
    $this->installConfig(['system']);
    // Install only the settings (validated against strict config schema). The
    // shipped view is skipped: it has pre-existing schema errors unrelated to
    // click tracking.
    $settings = Yaml::decode(file_get_contents(dirname(__DIR__, 3) . '/config/install/shortlink_manager.settings.yml'));
    $this->config('shortlink_manager.settings')->setData($settings)->save();
    $this->config('system.date')->set('timezone.default', 'America/Chicago')->save();
    $this->tracker = $this->container->get('shortlink_manager.click_tracker');
  }

  /**
   * Creates an enabled shortlink pointing at an external URL.
   */
  private function createShortlink(string $slug = 'abc123'): Shortlink {
    $shortlink = Shortlink::create([
      'label' => 'Test',
      'path' => 'go/' . $slug,
      'destination_override' => 'https://example.com/post',
      'status' => TRUE,
    ]);
    $shortlink->save();
    return $shortlink;
  }

  /**
   * Builds a request at a given time and makes it the current request.
   */
  private function request(int $time, string $user_agent = self::BROWSER, string $ip = '203.0.113.5', string $method = 'GET'): Request {
    $request = Request::create('/go/abc123', $method, [], [], [], [
      'REQUEST_TIME' => $time,
      'REQUEST_TIME_FLOAT' => (float) $time,
      'REMOTE_ADDR' => $ip,
      'HTTP_USER_AGENT' => $user_agent,
    ]);
    $request->setSession(new Session(new MockArraySessionStorage()));
    if ($user_agent === '') {
      $request->headers->remove('User-Agent');
    }
    $this->container->get('request_stack')->push($request);
    return $request;
  }

  /**
   * Follows a shortlink through the redirect controller.
   */
  private function click(int $time, string $user_agent = self::BROWSER, string $ip = '203.0.113.5', string $method = 'GET'): void {
    $request = $this->request($time, $user_agent, $ip, $method);
    $controller = ShortlinkRedirectController::create($this->container);
    $controller->redirectShortlink('abc123', $request);
    $this->container->get('request_stack')->pop();
  }

  /**
   * Reloads a shortlink, bypassing the static cache.
   */
  private function reload(Shortlink $shortlink): Shortlink {
    $storage = $this->container->get('entity_type.manager')->getStorage('shortlink');
    $storage->resetCache([$shortlink->id()]);
    return $storage->load($shortlink->id());
  }

  /**
   * Tests that the redirect keeps raw, human and unique counts apart.
   */
  public function testRedirectCounts(): void {
    $shortlink = $this->createShortlink();
    $t = 1_763_400_000;

    // Human click: counts everywhere.
    $this->click($t);
    // Same visitor 10 minutes later: human but not unique.
    $this->click($t + 600);
    // Link-preview crawler, HEAD probe and empty UA: raw only.
    $this->click($t + 700, 'facebookexternalhit/1.1');
    $this->click($t + 800, self::BROWSER, '203.0.113.5', 'HEAD');
    $this->click($t + 900, '');
    // Same visitor 31 minutes after their last human click: unique again.
    $this->click($t + 600 + 1860);
    // Different visitor: unique.
    $this->click($t + 3000, self::BROWSER, '198.51.100.7');

    $shortlink = $this->reload($shortlink);
    $this->assertSame(7, (int) $shortlink->get('click_count')->value);
    $this->assertSame(4, (int) $shortlink->get('human_click_count')->value);
    $this->assertSame(3, (int) $shortlink->get('unique_click_count')->value);
    // Bot clicks after the last human one must not move last_accessed.
    $this->assertSame($t + 3000, (int) $shortlink->get('last_accessed')->value);

    $this->click($t + 4000, 'Twitterbot/1.0');
    $this->assertSame($t + 3000, (int) $this->reload($shortlink)->get('last_accessed')->value);

    $bots = $this->container->get('database')->select('shortlink_clicks', 'sc')
      ->condition('is_bot', 1)->countQuery()->execute()->fetchField();
    $this->assertSame(4, (int) $bots);
  }

  /**
   * Tests that a zero window makes every human click unique.
   */
  public function testZeroWindow(): void {
    $this->config('shortlink_manager.settings')->set('click_tracking.unique_click_window_minutes', 0)->save();
    $shortlink = $this->createShortlink();
    $this->click(1_763_400_000);
    $this->click(1_763_400_010);
    $this->assertSame(2, (int) $this->reload($shortlink)->get('unique_click_count')->value);
  }

  /**
   * Inserts a raw click log row.
   */
  private function insertClick(int $shortlink_id, int $timestamp, string $user_agent = self::BROWSER, string $ip = '203.0.113.5'): void {
    $this->container->get('database')->insert('shortlink_clicks')->fields([
      'shortlink_id' => $shortlink_id,
      'timestamp' => $timestamp,
      'user_agent' => $user_agent,
      'ip_hash' => hash('sha256', $ip),
      'is_bot' => 0,
      'is_unique' => 0,
    ])->execute();
  }

  /**
   * Returns the daily table keyed by day.
   */
  private function daily(): array {
    $rows = $this->container->get('database')->select('shortlink_clicks_daily', 'd')
      ->fields('d', ['day', 'raw_count', 'human_count', 'unique_count'])
      ->orderBy('day')
      ->execute()
      ->fetchAll();
    $daily = [];
    foreach ($rows as $row) {
      $daily[$row->day] = (array) $row;
    }
    return $daily;
  }

  /**
   * Tests reclassification, daily rollup and purge protection.
   */
  public function testReclassifyRollupAndPurge(): void {
    $id = (int) $this->createShortlink()->id();
    $chicago = new \DateTimeZone('America/Chicago');
    $at = fn(string $local): int => (new \DateTimeImmutable($local, $chicago))->getTimestamp();

    // 2026-11-16: two human clicks 5 min apart and one bot.
    $this->insertClick($id, $at('2026-11-16 10:00'));
    $this->insertClick($id, $at('2026-11-16 10:05'));
    $this->insertClick($id, $at('2026-11-16 10:06'), 'got (https://github.com/sindresorhus/got)');
    // 23:30 Chicago on 11-17 is 05:30 UTC on 11-18: must land on 11-17.
    $this->insertClick($id, $at('2026-11-17 23:30'), self::BROWSER, '198.51.100.7');
    // Today (incomplete): never rolled up.
    $this->insertClick($id, $at('2026-11-18 09:00'));

    $totals = $this->tracker->reclassifyAll();
    $this->assertSame(['total' => 5, 'bots' => 1, 'unique' => 3], $totals);

    $counts = $this->tracker->getLoggedCountsByShortlink();
    $this->assertSame(['human' => 4, 'unique' => 3], $counts[$id]);

    $this->setRequestTime($at('2026-11-18 12:00'));

    // Nothing rolled up yet, so purge must not delete anything.
    $this->assertSame(0, $this->tracker->purgeOldClicks($at('2026-11-18 12:00')));

    $this->assertSame(2, $this->tracker->rollupCompletedDays());
    $daily = $this->daily();
    $this->assertSame(['2026-11-16', '2026-11-17'], array_keys($daily));
    $this->assertEquals(['day' => '2026-11-16', 'raw_count' => 3, 'human_count' => 2, 'unique_count' => 1], $daily['2026-11-16']);
    $this->assertEquals(['day' => '2026-11-17', 'raw_count' => 1, 'human_count' => 1, 'unique_count' => 1], $daily['2026-11-17']);

    // Running again the same day rolls up nothing new.
    $this->assertSame(0, $this->tracker->rollupCompletedDays());

    // A purge threshold past today is clamped to the end of the last
    // rolled-up day, so today's un-rolled click survives.
    $this->assertSame(4, $this->tracker->purgeOldClicks($at('2026-11-19 00:00')));
    $remaining = $this->container->get('database')->select('shortlink_clicks', 'sc')
      ->countQuery()->execute()->fetchField();
    $this->assertSame(1, (int) $remaining);
    // Daily totals outlive the purge.
    $this->assertCount(2, $this->daily());

    // Next day: 11-18 gets rolled up from the retained row.
    $this->setRequestTime($at('2026-11-19 01:00'));
    $this->assertSame(1, $this->tracker->rollupCompletedDays());
    $this->assertEquals(1, $this->daily()['2026-11-18']['raw_count']);
  }

  /**
   * Tests that rebuilding after a pattern change updates retained days only.
   */
  public function testRebuildRetainedDays(): void {
    $id = (int) $this->createShortlink()->id();
    $chicago = new \DateTimeZone('America/Chicago');
    $at = fn(string $local): int => (new \DateTimeImmutable($local, $chicago))->getTimestamp();

    $this->insertClick($id, $at('2026-11-15 10:00'), 'CustomFetcher/1.0');
    $this->insertClick($id, $at('2026-11-16 10:00'), 'CustomFetcher/1.0');
    $this->insertClick($id, $at('2026-11-17 10:00'), 'CustomFetcher/1.0');
    $this->tracker->reclassifyAll();
    $this->setRequestTime($at('2026-11-18 12:00'));
    $this->tracker->rollupCompletedDays();
    $this->assertEquals(1, $this->daily()['2026-11-16']['human_count']);

    $patterns = ShortlinkClickTracker::DEFAULT_BOT_PATTERNS;
    $patterns[] = 'CustomFetcher';
    $this->config('shortlink_manager.settings')->set('click_tracking.bot_user_agent_patterns', $patterns)->save();
    $this->tracker->reclassifyAll();

    // The oldest logged day may be partially purged, so it's left alone.
    $this->assertSame(2, $this->tracker->rebuildRetainedDays());
    $daily = $this->daily();
    $this->assertEquals(1, $daily['2026-11-15']['human_count']);
    $this->assertEquals(0, $daily['2026-11-16']['human_count']);
    $this->assertEquals(0, $daily['2026-11-17']['human_count']);
  }

  /**
   * Sets the current request time used by the time service.
   */
  private function setRequestTime(int $time): void {
    $this->request($time);
  }

}
