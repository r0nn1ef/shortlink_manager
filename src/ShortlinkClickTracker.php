<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager;

use Drupal\Core\Database\Connection;
use Symfony\Component\HttpFoundation\Request;

/**
 * Service for tracking shortlink clicks with granular logging.
 */
class ShortlinkClickTracker {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * Constructs a ShortlinkClickTracker service.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * Records a click event for a shortlink.
   *
   * @param int $shortlink_id
   *   The shortlink entity ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   */
  public function recordClick(int $shortlink_id, Request $request): void {
    $ip = $request->getClientIp();
    $ip_hash = $ip ? hash('sha256', $ip) : NULL;

    $this->database->insert('shortlink_clicks')
      ->fields([
        'shortlink_id' => $shortlink_id,
        'timestamp' => \Drupal::time()->getRequestTime(),
        'referrer' => $request->headers->get('referer') ? mb_substr($request->headers->get('referer'), 0, 2048) : NULL,
        'user_agent' => $request->headers->get('User-Agent') ? mb_substr($request->headers->get('User-Agent'), 0, 512) : NULL,
        'ip_hash' => $ip_hash,
      ])
      ->execute();
  }

  /**
   * Gets click records for a specific shortlink within a time range.
   *
   * @param int $shortlink_id
   *   The shortlink entity ID.
   * @param int $from
   *   Start timestamp.
   * @param int $to
   *   End timestamp.
   *
   * @return array
   *   An array of click records.
   */
  public function getClicksByShortlink(int $shortlink_id, int $from, int $to): array {
    return $this->database->select('shortlink_clicks', 'sc')
      ->fields('sc')
      ->condition('shortlink_id', $shortlink_id)
      ->condition('timestamp', $from, '>=')
      ->condition('timestamp', $to, '<=')
      ->orderBy('timestamp', 'DESC')
      ->execute()
      ->fetchAll();
  }

  /**
   * Gets the top shortlinks by click count within a time range.
   *
   * @param int $limit
   *   Maximum number of results.
   * @param int $from
   *   Start timestamp.
   * @param int $to
   *   End timestamp.
   *
   * @return array
   *   An array of objects with shortlink_id and click_count.
   */
  public function getTopShortlinks(int $limit, int $from, int $to): array {
    $query = $this->database->select('shortlink_clicks', 'sc');
    $query->addField('sc', 'shortlink_id');
    $query->addExpression('COUNT(*)', 'click_count');
    $query->condition('timestamp', $from, '>=');
    $query->condition('timestamp', $to, '<=');
    $query->groupBy('shortlink_id');
    $query->orderBy('click_count', 'DESC');
    $query->range(0, $limit);

    return $query->execute()->fetchAll();
  }

  /**
   * Gets total click count within a time range.
   *
   * @param int $from
   *   Start timestamp.
   * @param int $to
   *   End timestamp.
   *
   * @return int
   *   The total number of clicks.
   */
  public function getTotalClicks(int $from, int $to): int {
    return (int) $this->database->select('shortlink_clicks', 'sc')
      ->condition('timestamp', $from, '>=')
      ->condition('timestamp', $to, '<=')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Gets recent click events.
   *
   * @param int $limit
   *   Maximum number of results.
   *
   * @return array
   *   An array of recent click records.
   */
  public function getRecentClicks(int $limit = 10): array {
    return $this->database->select('shortlink_clicks', 'sc')
      ->fields('sc')
      ->orderBy('timestamp', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll();
  }

  /**
   * Purges click records older than the given timestamp.
   *
   * @param int $before_timestamp
   *   Delete records older than this timestamp.
   *
   * @return int
   *   The number of records deleted.
   */
  public function purgeOldClicks(int $before_timestamp): int {
    return (int) $this->database->delete('shortlink_clicks')
      ->condition('timestamp', $before_timestamp, '<')
      ->execute();
  }

}
