<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\State\StateInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Service for tracking shortlink clicks with granular logging.
 *
 * Every request to a shortlink is logged. Each row is classified as bot or
 * human, and human rows are flagged as "unique" when no other human click
 * from the same hashed IP hit the same shortlink within the configured
 * window. Complete days are rolled up into shortlink_clicks_daily on cron so
 * totals survive the click log purge.
 */
class ShortlinkClickTracker {

  /**
   * Default user agent substrings treated as bots.
   *
   * Matching is case-insensitive substring matching, not regex.
   */
  public const DEFAULT_BOT_PATTERNS = [
    'facebookexternalhit',
    'LinkedInBot',
    'Twitterbot',
    'Slackbot',
    'Discordbot',
    'TelegramBot',
    'WhatsApp',
    'Mastodon/',
    'Bluesky Cardyb',
    'SummalyBot',
    'got (',
    'Googlebot',
    'bingbot',
    'bot',
    'crawler',
    'spider',
    'preview',
  ];

  /**
   * Default unique-click window in minutes (matches the GA4 session timeout).
   */
  public const DEFAULT_UNIQUE_WINDOW_MINUTES = 30;

  /**
   * State key holding the start timestamp of the last rolled-up day.
   */
  public const STATE_LAST_ROLLUP_DAY = 'shortlink_manager.daily_rollup_last_day';

  /**
   * Number of click log rows processed per reclassification chunk.
   */
  protected const CHUNK_SIZE = 1000;

  /**
   * Constructs a ShortlinkClickTracker service.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(
    protected Connection $database,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
    protected StateInterface $state,
  ) {}

  /**
   * Records a click event for a shortlink.
   *
   * @param int $shortlink_id
   *   The shortlink entity ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return array{is_bot: bool, is_unique: bool}
   *   How the click was classified.
   */
  public function recordClick(int $shortlink_id, Request $request): array {
    $ip = $request->getClientIp();
    $ip_hash = $ip ? hash('sha256', $ip) : NULL;
    $user_agent = $request->headers->get('User-Agent');
    $timestamp = $this->time->getRequestTime();

    $is_bot = $this->isBotRequest($request->getMethod(), $user_agent);
    $is_unique = !$is_bot && $this->isUniqueClick($shortlink_id, $ip_hash, $timestamp);

    $this->database->insert('shortlink_clicks')
      ->fields([
        'shortlink_id' => $shortlink_id,
        'timestamp' => $timestamp,
        'referrer' => $request->headers->get('referer') ? mb_substr($request->headers->get('referer'), 0, 2048) : NULL,
        'user_agent' => $user_agent ? mb_substr($user_agent, 0, 512) : NULL,
        'ip_hash' => $ip_hash,
        'is_bot' => (int) $is_bot,
        'is_unique' => (int) $is_unique,
      ])
      ->execute();

    return ['is_bot' => $is_bot, 'is_unique' => $is_unique];
  }

  /**
   * Determines whether a request looks like a bot or link-preview crawler.
   *
   * @param string $method
   *   The HTTP method. HEAD requests are always treated as bots, since
   *   browsers following a link send GET and crawlers often probe with HEAD.
   * @param string|null $user_agent
   *   The user agent string. An empty user agent is treated as a bot.
   *
   * @return bool
   *   TRUE if the request should be counted as a bot.
   */
  public function isBotRequest(string $method, ?string $user_agent): bool {
    return static::matchesBot($method, $user_agent, $this->getBotPatterns());
  }

  /**
   * Pure bot classification logic.
   *
   * @param string $method
   *   The HTTP method.
   * @param string|null $user_agent
   *   The user agent string.
   * @param string[] $patterns
   *   Case-insensitive substrings that mark a user agent as a bot.
   *
   * @return bool
   *   TRUE if the request should be counted as a bot.
   */
  public static function matchesBot(string $method, ?string $user_agent, array $patterns): bool {
    if (strtoupper($method) === 'HEAD') {
      return TRUE;
    }
    $user_agent = trim((string) $user_agent);
    if ($user_agent === '') {
      return TRUE;
    }
    foreach ($patterns as $pattern) {
      $pattern = trim((string) $pattern);
      if ($pattern !== '' && stripos($user_agent, $pattern) !== FALSE) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Gets the configured bot user agent patterns.
   *
   * @return string[]
   *   The patterns.
   */
  public function getBotPatterns(): array {
    $patterns = $this->configFactory->get('shortlink_manager.settings')->get('click_tracking.bot_user_agent_patterns');
    return is_array($patterns) ? $patterns : static::DEFAULT_BOT_PATTERNS;
  }

  /**
   * Gets the unique-click window in seconds.
   *
   * @return int
   *   The window in seconds; 0 means every human click is unique.
   */
  public function getUniqueWindowSeconds(): int {
    $minutes = $this->configFactory->get('shortlink_manager.settings')->get('click_tracking.unique_click_window_minutes');
    $minutes = $minutes === NULL ? static::DEFAULT_UNIQUE_WINDOW_MINUTES : (int) $minutes;
    return max(0, $minutes) * 60;
  }

  /**
   * Checks whether a human click starts a new "visit".
   *
   * A click is unique unless another human click from the same hashed IP hit
   * the same shortlink within the window before it (an inactivity window, like
   * a GA4 session). Clicks without an IP hash are always unique.
   *
   * @param int $shortlink_id
   *   The shortlink entity ID.
   * @param string|null $ip_hash
   *   The hashed client IP.
   * @param int $timestamp
   *   The click timestamp.
   * @param int|null $before_click_id
   *   When reclassifying, only consider rows logged before this click ID.
   *
   * @return bool
   *   TRUE if the click counts as unique.
   */
  protected function isUniqueClick(int $shortlink_id, ?string $ip_hash, int $timestamp, ?int $before_click_id = NULL): bool {
    $window = $this->getUniqueWindowSeconds();
    if ($ip_hash === NULL || $window === 0) {
      return TRUE;
    }
    $query = $this->database->select('shortlink_clicks', 'sc')
      ->condition('shortlink_id', $shortlink_id)
      ->condition('ip_hash', $ip_hash)
      ->condition('is_bot', 0)
      ->condition('timestamp', $timestamp - $window, '>=')
      ->condition('timestamp', $timestamp, '<=');
    if ($before_click_id !== NULL) {
      $query->condition('click_id', $before_click_id, '<');
    }
    return (int) $query->countQuery()->execute()->fetchField() === 0;
  }

  /**
   * Re-applies bot and unique classification to the whole click log.
   *
   * Rows are processed in click_id order so uniqueness is evaluated against
   * already reclassified earlier rows.
   *
   * @return array{total: int, bots: int, unique: int}
   *   Counts after reclassification.
   */
  public function reclassifyAll(): array {
    $patterns = $this->getBotPatterns();
    $totals = ['total' => 0, 'bots' => 0, 'unique' => 0];
    $last_id = 0;

    do {
      $rows = $this->database->select('shortlink_clicks', 'sc')
        ->fields('sc', ['click_id', 'shortlink_id', 'timestamp', 'user_agent', 'ip_hash'])
        ->condition('click_id', $last_id, '>')
        ->orderBy('click_id')
        ->range(0, static::CHUNK_SIZE)
        ->execute()
        ->fetchAll();

      foreach ($rows as $row) {
        $last_id = (int) $row->click_id;
        // The original HTTP method isn't logged, so only the user agent can be
        // checked for existing rows.
        $is_bot = static::matchesBot('GET', $row->user_agent, $patterns);
        // Mark the row's bot status first so later rows see it.
        $this->database->update('shortlink_clicks')
          ->fields(['is_bot' => (int) $is_bot, 'is_unique' => 0])
          ->condition('click_id', $last_id)
          ->execute();
        $is_unique = !$is_bot && $this->isUniqueClick((int) $row->shortlink_id, $row->ip_hash, (int) $row->timestamp, $last_id);
        if ($is_unique) {
          $this->database->update('shortlink_clicks')
            ->fields(['is_unique' => 1])
            ->condition('click_id', $last_id)
            ->execute();
        }
        $totals['total']++;
        $totals['bots'] += (int) $is_bot;
        $totals['unique'] += (int) $is_unique;
      }
    } while (count($rows) === static::CHUNK_SIZE);

    return $totals;
  }

  /**
   * Gets human and unique click counts per shortlink from the click log.
   *
   * @return array<int, array{human: int, unique: int}>
   *   Counts keyed by shortlink ID.
   */
  public function getLoggedCountsByShortlink(): array {
    $query = $this->database->select('shortlink_clicks', 'sc');
    $query->addField('sc', 'shortlink_id');
    $query->addExpression('SUM(CASE WHEN sc.is_bot = 0 THEN 1 ELSE 0 END)', 'human');
    $query->addExpression('SUM(sc.is_unique)', 'unique_count');
    $query->groupBy('sc.shortlink_id');

    $counts = [];
    foreach ($query->execute() as $row) {
      $counts[(int) $row->shortlink_id] = [
        'human' => (int) $row->human,
        'unique' => (int) $row->unique_count,
      ];
    }
    return $counts;
  }

  /**
   * Rolls up every complete day not yet stored in shortlink_clicks_daily.
   *
   * Days are calendar days in the site's default timezone. Today is never
   * rolled up because it isn't complete.
   *
   * @return int
   *   The number of days rolled up.
   */
  public function rollupCompletedDays(): int {
    $today = $this->getDayStart($this->time->getRequestTime());
    $last = $this->state->get(static::STATE_LAST_ROLLUP_DAY);

    if ($last === NULL) {
      $oldest = $this->database->select('shortlink_clicks', 'sc');
      $oldest->addExpression('MIN(sc.timestamp)', 'oldest');
      $oldest_ts = $oldest->execute()->fetchField();
      if (!$oldest_ts) {
        // Nothing logged yet: start rolling up from today onward.
        $this->state->set(static::STATE_LAST_ROLLUP_DAY, $this->shiftDays($today, -1));
        return 0;
      }
      $start = $this->getDayStart((int) $oldest_ts);
    }
    else {
      $start = $this->shiftDays((int) $last, 1);
    }

    $days = 0;
    for ($day = $start; $day < $today; $day = $this->shiftDays($day, 1)) {
      $this->rollupDay($day);
      $this->state->set(static::STATE_LAST_ROLLUP_DAY, $day);
      $days++;
    }
    return $days;
  }

  /**
   * Recomputes daily totals for rolled-up days still fully in the click log.
   *
   * Used after reclassification. The oldest logged day is skipped because the
   * purge may have removed part of it, unless it has no daily row yet.
   *
   * @return int
   *   The number of days recomputed.
   */
  public function rebuildRetainedDays(): int {
    $last = $this->state->get(static::STATE_LAST_ROLLUP_DAY);
    if ($last === NULL) {
      return $this->rollupCompletedDays();
    }
    $oldest = $this->database->select('shortlink_clicks', 'sc');
    $oldest->addExpression('MIN(sc.timestamp)', 'oldest');
    $oldest_ts = $oldest->execute()->fetchField();
    if (!$oldest_ts) {
      return 0;
    }

    $first_day = $this->getDayStart((int) $oldest_ts);
    $has_first_day = (bool) $this->database->select('shortlink_clicks_daily', 'd')
      ->condition('day', $this->formatDay($first_day))
      ->countQuery()
      ->execute()
      ->fetchField();
    $start = $has_first_day ? $this->shiftDays($first_day, 1) : $first_day;

    $days = 0;
    for ($day = $start; $day <= (int) $last; $day = $this->shiftDays($day, 1)) {
      $this->rollupDay($day);
      $days++;
    }
    return $days;
  }

  /**
   * Writes the daily totals for one day, replacing any existing rows.
   *
   * @param int $day_start
   *   The timestamp of the start of the day.
   */
  protected function rollupDay(int $day_start): void {
    $day_end = $this->shiftDays($day_start, 1);
    $day = $this->formatDay($day_start);

    $query = $this->database->select('shortlink_clicks', 'sc');
    $query->addField('sc', 'shortlink_id');
    $query->addExpression('COUNT(*)', 'raw_count');
    $query->addExpression('SUM(CASE WHEN sc.is_bot = 0 THEN 1 ELSE 0 END)', 'human_count');
    $query->addExpression('SUM(sc.is_unique)', 'unique_count');
    $query->condition('sc.timestamp', $day_start, '>=');
    $query->condition('sc.timestamp', $day_end, '<');
    $query->groupBy('sc.shortlink_id');
    $rows = $query->execute()->fetchAll();

    $transaction = $this->database->startTransaction();
    $this->database->delete('shortlink_clicks_daily')
      ->condition('day', $day)
      ->execute();
    foreach ($rows as $row) {
      $this->database->insert('shortlink_clicks_daily')
        ->fields([
          'shortlink_id' => (int) $row->shortlink_id,
          'day' => $day,
          'raw_count' => (int) $row->raw_count,
          'human_count' => (int) $row->human_count,
          'unique_count' => (int) $row->unique_count,
        ])
        ->execute();
    }
    unset($transaction);
  }

  /**
   * Gets the last day rolled up into shortlink_clicks_daily.
   *
   * @return string|null
   *   The day as Y-m-d in the rollup timezone, or NULL before the first
   *   rollup.
   */
  public function getLastRolledUpDay(): ?string {
    $last = $this->state->get(static::STATE_LAST_ROLLUP_DAY);
    return $last === NULL ? NULL : $this->formatDay((int) $last);
  }

  /**
   * Gets the timezone used for daily rollups (the site default timezone).
   */
  public function getTimezone(): \DateTimeZone {
    $timezone = $this->configFactory->get('system.date')->get('timezone.default') ?: 'UTC';
    return new \DateTimeZone($timezone);
  }

  /**
   * Gets the start of the day containing a timestamp.
   */
  protected function getDayStart(int $timestamp): int {
    return (new \DateTimeImmutable('@' . $timestamp))
      ->setTimezone($this->getTimezone())
      ->setTime(0, 0)
      ->getTimestamp();
  }

  /**
   * Moves a day-start timestamp by a number of calendar days (DST-safe).
   */
  protected function shiftDays(int $day_start, int $days): int {
    return (new \DateTimeImmutable('@' . $day_start))
      ->setTimezone($this->getTimezone())
      ->modify(sprintf('%+d days', $days))
      ->setTime(0, 0)
      ->getTimestamp();
  }

  /**
   * Formats a day-start timestamp as Y-m-d in the rollup timezone.
   */
  protected function formatDay(int $day_start): string {
    return (new \DateTimeImmutable('@' . $day_start))
      ->setTimezone($this->getTimezone())
      ->format('Y-m-d');
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
   * Rows from days that haven't been rolled up yet are never purged, so the
   * daily totals stay complete.
   *
   * @param int $before_timestamp
   *   Delete records older than this timestamp.
   *
   * @return int
   *   The number of records deleted.
   */
  public function purgeOldClicks(int $before_timestamp): int {
    $last = $this->state->get(static::STATE_LAST_ROLLUP_DAY);
    if ($last === NULL) {
      return 0;
    }
    $before_timestamp = min($before_timestamp, $this->shiftDays((int) $last, 1));
    return (int) $this->database->delete('shortlink_clicks')
      ->condition('timestamp', $before_timestamp, '<')
      ->execute();
  }

}
