<?php

declare(strict_types=1);

namespace Drupal\Tests\shortlink_manager\Kernel;

use Drupal\Component\Datetime\TimeInterface;

/**
 * A datetime.time replacement that returns a fixed, test-controlled time.
 *
 * Core's time service reads REQUEST_TIME from a request whose choice differs
 * between Drupal 11 minors, so tests set the clock here instead. The time is
 * static so it survives container rebuilds within a test.
 */
final class FrozenTime implements TimeInterface {

  /**
   * The frozen Unix timestamp.
   */
  public static int $time = 0;

  /**
   * {@inheritdoc}
   */
  public function getRequestTime(): int {
    return self::$time;
  }

  /**
   * {@inheritdoc}
   */
  public function getRequestMicroTime(): float {
    return (float) self::$time;
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentTime(): int {
    return self::$time;
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentMicroTime(): float {
    return (float) self::$time;
  }

}
