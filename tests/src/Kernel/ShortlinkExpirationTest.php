<?php

declare(strict_types=1);

namespace Drupal\Tests\shortlink_manager\Kernel;

use Drupal\shortlink_manager\Entity\Shortlink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests shortlink expiration on access, in cron and in the queue worker.
 *
 * The only expiration action the module has is disabling the link (and
 * returning 404 from then on), so that is what every case checks.
 *
 * @group shortlink_manager
 */
#[Group('shortlink_manager')]
#[RunTestsInSeparateProcesses]
final class ShortlinkExpirationTest extends ShortlinkKernelTestBase {

  private const DAY = 86400;

  /**
   * Data provider for testIsExpired().
   *
   * Each case is [field values, expected isExpired() at NOW].
   */
  public static function expirationProvider(): array {
    $now = static::NOW;
    $day = self::DAY;
    return [
      'no rules' => [[], FALSE],
      'zero values mean disabled' => [
        [
          'expires_at' => 0,
          'max_clicks' => 0,
          'expire_if_inactive_days' => 0,
          'click_count' => 500,
          'last_accessed' => $now - 365 * $day,
        ],
        FALSE,
      ],
      'date in the future' => [['expires_at' => $now + 1], FALSE],
      'date reached exactly' => [['expires_at' => $now], TRUE],
      'date in the past' => [['expires_at' => $now - $day], TRUE],
      'below click limit' => [['max_clicks' => 5, 'click_count' => 4], FALSE],
      'at click limit' => [['max_clicks' => 5, 'click_count' => 5], TRUE],
      'over click limit' => [['max_clicks' => 5, 'click_count' => 9], TRUE],
      'recently accessed' => [['expire_if_inactive_days' => 7, 'last_accessed' => $now - 6 * $day], FALSE],
      'inactive too long' => [['expire_if_inactive_days' => 7, 'last_accessed' => $now - 8 * $day], TRUE],
      // Shortlink has no "created" base field, so a link that was never
      // clicked can't be measured for inactivity and never expires that way.
      // This used to throw "Field created is unknown".
      'never accessed' => [['expire_if_inactive_days' => 7], FALSE],
      'any rule is enough' => [
        ['expires_at' => $now + $day, 'max_clicks' => 5, 'click_count' => 5],
        TRUE,
      ],
    ];
  }

  /**
   * Tests Shortlink::isExpired() for each rule.
   */
  #[DataProvider('expirationProvider')]
  public function testIsExpired(array $values, bool $expected): void {
    $shortlink = $this->createShortlink($values);
    $this->assertSame($expected, $shortlink->isExpired());
  }

  /**
   * Asserts that following a link 404s, disables it and records no click.
   */
  private function assertExpiresOnAccess(Shortlink $shortlink, int $time = self::NOW): void {
    $shortlink = $this->reload($shortlink);
    $clicks = (int) $shortlink->get('click_count')->value;
    try {
      $this->follow('abc123', $time);
      $this->fail('Expected a 404 for an expired shortlink.');
    }
    catch (NotFoundHttpException) {
      $this->addToAssertionCount(1);
    }
    $shortlink = $this->reload($shortlink);
    $this->assertFalse($shortlink->isEnabled());
    $this->assertSame($clicks, (int) $shortlink->get('click_count')->value);
  }

  /**
   * Tests that a link past its date 404s and is disabled on access.
   */
  public function testExpiredByDateOnAccess(): void {
    $shortlink = $this->createShortlink(['expires_at' => static::NOW + self::DAY]);
    $this->assertSame(301, $this->follow()->getStatusCode());
    $this->assertExpiresOnAccess($shortlink, static::NOW + self::DAY);
  }

  /**
   * Tests that the click limit lets exactly max_clicks redirects through.
   */
  public function testExpiredByClicksOnAccess(): void {
    $shortlink = $this->createShortlink(['max_clicks' => 2]);
    $this->follow();
    $this->follow();
    $this->assertExpiresOnAccess($shortlink);
  }

  /**
   * Tests that an inactive link 404s, while each click keeps it alive.
   */
  public function testExpiredByInactivityOnAccess(): void {
    $shortlink = $this->createShortlink(['expire_if_inactive_days' => 7]);
    // The first click on a never-clicked link goes through.
    $this->follow('abc123', static::NOW);
    // A click after 6 days resets the inactivity clock.
    $this->follow('abc123', static::NOW + 6 * self::DAY);
    $this->follow('abc123', static::NOW + 12 * self::DAY);
    $this->assertExpiresOnAccess($shortlink, static::NOW + 20 * self::DAY);
  }

  /**
   * Returns the number of items in the expiration queue.
   */
  private function queueSize(): int {
    return (int) $this->container->get('queue')->get('shortlink_expiration')->numberOfItems();
  }

  /**
   * Tests which shortlinks cron queues for expiration checks.
   */
  public function testCronQueuesLinksWithRules(): void {
    $this->createShortlink(['path' => 'go/norules']);
    $dated = $this->createShortlink(['path' => 'go/dated', 'expires_at' => static::NOW + self::DAY]);
    $capped = $this->createShortlink(['path' => 'go/capped', 'max_clicks' => 10]);
    $idle = $this->createShortlink(['path' => 'go/idle', 'expire_if_inactive_days' => 30]);
    $this->createShortlink(['path' => 'go/off', 'max_clicks' => 1, 'status' => FALSE]);

    // Nothing is queued while expiration processing is turned off.
    shortlink_manager_cron();
    $this->assertSame(0, $this->queueSize());

    $this->config('shortlink_manager.settings')->set('expiration.enabled', TRUE)->save();
    shortlink_manager_cron();
    $this->assertSame(3, $this->queueSize());

    $queue = $this->container->get('queue')->get('shortlink_expiration');
    $queued = [];
    while ($item = $queue->claimItem()) {
      $queued[] = $item->data['shortlink_id'];
      $queue->deleteItem($item);
    }
    $this->assertEqualsCanonicalizing([$dated->id(), $capped->id(), $idle->id()], $queued);
  }

  /**
   * Tests the ShortlinkExpirationWorker queue worker.
   */
  public function testExpirationWorker(): void {
    $expired = $this->createShortlink(['path' => 'go/old', 'expires_at' => static::NOW - 1]);
    $maxed = $this->createShortlink(['path' => 'go/maxed', 'max_clicks' => 3, 'click_count' => 3]);
    $idle = $this->createShortlink([
      'path' => 'go/idle',
      'expire_if_inactive_days' => 7,
      'last_accessed' => static::NOW - 8 * self::DAY,
    ]);
    $live = $this->createShortlink([
      'path' => 'go/live',
      'expires_at' => static::NOW + self::DAY,
      'max_clicks' => 3,
      'click_count' => 2,
    ]);

    /** @var \Drupal\Core\Queue\QueueWorkerInterface $worker */
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance('shortlink_expiration');
    foreach ([$expired, $maxed, $idle, $live] as $shortlink) {
      $worker->processItem(['shortlink_id' => $shortlink->id()]);
    }

    $this->assertFalse($this->reload($expired)->isEnabled());
    $this->assertFalse($this->reload($maxed)->isEnabled());
    $this->assertFalse($this->reload($idle)->isEnabled());
    $this->assertTrue($this->reload($live)->isEnabled());

    // Never-clicked links with an inactivity rule are processed without error.
    $never = $this->createShortlink(['path' => 'go/never', 'expire_if_inactive_days' => 7]);
    $worker->processItem(['shortlink_id' => $never->id()]);
    $this->assertTrue($this->reload($never)->isEnabled());

    // Malformed, deleted and already-disabled items are skipped quietly.
    $worker->processItem([]);
    $worker->processItem(['shortlink_id' => 999]);
    $worker->processItem(['shortlink_id' => $expired->id()]);
    $this->assertFalse($this->reload($expired)->isEnabled());
  }

  /**
   * Tests cron and the queue end to end.
   */
  public function testCronRun(): void {
    $this->config('shortlink_manager.settings')->set('expiration.enabled', TRUE)->save();
    $expired = $this->createShortlink(['path' => 'go/old', 'expires_at' => static::NOW - 1]);
    $live = $this->createShortlink(['path' => 'go/live', 'expires_at' => static::NOW + self::DAY]);

    $this->container->get('cron')->run();

    $this->assertSame(0, $this->queueSize());
    $this->assertFalse($this->reload($expired)->isEnabled());
    $this->assertTrue($this->reload($live)->isEnabled());
  }

}
