<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Read-only daily click statistics built from shortlink_clicks_daily.
 *
 * Each row carries the shortlink's UTM parameters as a redirect would send
 * them today, so rows can be joined to analytics tools such as GA4. Only
 * aggregates are exposed: no IP hashes, user agents or referrers.
 */
class ShortlinkStats {

  /**
   * Query parameters reported per row and used for the per-source summary.
   */
  public const UTM_KEYS = [
    'utm_source',
    'utm_medium',
    'utm_campaign',
    'utm_term',
    'utm_content',
    'utm_id',
  ];

  /**
   * Constructs a ShortlinkStats service.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\shortlink_manager\ShortlinkUtmResolver $utmResolver
   *   The UTM parameter resolver.
   * @param \Drupal\shortlink_manager\ShortlinkClickTracker $clickTracker
   *   The click tracker.
   */
  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ShortlinkUtmResolver $utmResolver,
    protected ShortlinkClickTracker $clickTracker,
  ) {}

  /**
   * Builds daily statistics for a date range.
   *
   * @param string $from
   *   First day, Y-m-d, inclusive.
   * @param string $to
   *   Last day, Y-m-d, inclusive.
   * @param array $filters
   *   Optional filters: 'shortlink' (ID), 'utm_set' (UTM set ID),
   *   'utm_source' (the resolved value, as sent), 'target_entity_type' and
   *   'target_entity_id'.
   *
   * @return array
   *   An array with:
   *   - rows: one entry per shortlink per day, ordered by day then ID.
   *   - summary: totals per utm_source (NULL for links without one), ordered
   *     by human clicks, highest first.
   *   - totals: raw, human and unique totals for the whole window.
   */
  public function getDailyStats(string $from, string $to, array $filters = []): array {
    $entity_filtered = FALSE;
    $ids = NULL;
    $entity_query = $this->entityTypeManager->getStorage('shortlink')->getQuery()->accessCheck(FALSE);
    $fields = [
      'shortlink' => 'id',
      'utm_set' => 'utm_set',
      'target_entity_type' => 'target_entity_type',
      'target_entity_id' => 'target_entity_id',
    ];
    foreach ($fields as $filter => $field) {
      if (isset($filters[$filter]) && $filters[$filter] !== '') {
        $entity_query->condition($field, $filters[$filter]);
        $entity_filtered = TRUE;
      }
    }
    if ($entity_filtered) {
      $ids = array_map('intval', array_values($entity_query->execute()));
      if (!$ids) {
        return $this->emptyResult();
      }
    }

    $query = $this->database->select('shortlink_clicks_daily', 'd')
      ->fields('d', ['shortlink_id', 'day', 'raw_count', 'human_count', 'unique_count'])
      ->condition('d.day', $from, '>=')
      ->condition('d.day', $to, '<=')
      ->orderBy('d.day')
      ->orderBy('d.shortlink_id');
    if ($ids !== NULL) {
      $query->condition('d.shortlink_id', $ids, 'IN');
    }
    $daily = $query->execute()->fetchAll();

    $link_ids = array_unique(array_map(static fn($row) => (int) $row->shortlink_id, $daily));
    $links = $this->describeShortlinks($link_ids);

    $source_filter = $filters['utm_source'] ?? '';
    $rows = [];
    $summary = [];
    $totals = ['raw_count' => 0, 'human_count' => 0, 'unique_count' => 0];
    foreach ($daily as $record) {
      $id = (int) $record->shortlink_id;
      $link = $links[$id] ?? $this->describeMissing($id);
      $source = $link['utm']['utm_source'];
      if ($source_filter !== '' && $source !== $source_filter) {
        continue;
      }
      $counts = [
        'raw_count' => (int) $record->raw_count,
        'human_count' => (int) $record->human_count,
        'unique_count' => (int) $record->unique_count,
      ];
      $rows[] = ['day' => $record->day] + $link + $counts;

      $key = $source ?? '';
      $summary[$key] ??= [
        'utm_source' => $source,
        'shortlinks' => [],
        'raw_count' => 0,
        'human_count' => 0,
        'unique_count' => 0,
      ];
      $summary[$key]['shortlinks'][$id] = TRUE;
      foreach ($counts as $name => $count) {
        $summary[$key][$name] += $count;
        $totals[$name] += $count;
      }
    }

    foreach ($summary as &$group) {
      $group['shortlinks'] = count($group['shortlinks']);
    }
    unset($group);
    $summary = array_values($summary);
    // Most human clicks first, then by source name.
    usort($summary, static fn(array $a, array $b) => [$b['human_count'], (string) $a['utm_source']]
      <=> [$a['human_count'], (string) $b['utm_source']]);

    return ['rows' => $rows, 'summary' => $summary, 'totals' => $totals];
  }

  /**
   * Gets the first day with daily totals (and so human/unique counts).
   *
   * Daily totals are built from the click log, which classifies bot and
   * unique clicks, so they start at the oldest click the log still held when
   * the daily table was first filled.
   *
   * @return string|null
   *   The day as Y-m-d, or NULL when there are no daily totals yet.
   */
  public function getFirstDay(): ?string {
    $query = $this->database->select('shortlink_clicks_daily', 'd');
    $query->addExpression('MIN(d.day)', 'first_day');
    $first = $query->execute()->fetchField();
    return $first ? (string) $first : NULL;
  }

  /**
   * Describes shortlinks for stats rows, resolving their UTM parameters.
   *
   * @param int[] $ids
   *   Shortlink IDs.
   *
   * @return array
   *   Descriptions keyed by shortlink ID.
   */
  protected function describeShortlinks(array $ids): array {
    if (!$ids) {
      return [];
    }
    $descriptions = [];
    /** @var \Drupal\shortlink_manager\ShortlinkInterface $shortlink */
    foreach ($this->entityTypeManager->getStorage('shortlink')->loadMultiple($ids) as $id => $shortlink) {
      $target = $this->utmResolver->loadTargetEntity($shortlink);
      $data = $this->utmResolver->buildTokenData($shortlink, $target);
      // Clear tokens that can't be resolved (e.g. the target was deleted)
      // rather than reporting the literal token text.
      $parameters = $this->utmResolver->resolve($shortlink, $data, ['clear' => TRUE]);

      $utm = [];
      foreach (static::UTM_KEYS as $key) {
        $value = $parameters[$key] ?? '';
        $utm[$key] = $value === '' ? NULL : (string) $value;
      }

      $descriptions[(int) $id] = [
        'shortlink_id' => (int) $id,
        'path' => $shortlink->get('path')->value,
        'label' => $shortlink->label(),
        'utm_set' => $shortlink->hasUtmSet() ? $shortlink->get('utm_set')->target_id : NULL,
        'target_entity_type' => $shortlink->getTargetEntityType() ?: NULL,
        'target_entity_id' => $shortlink->getTargetEntityId() ?: NULL,
        'utm' => $utm,
      ];
    }
    return $descriptions;
  }

  /**
   * Describes a shortlink that has daily totals but no longer exists.
   */
  protected function describeMissing(int $id): array {
    return [
      'shortlink_id' => $id,
      'path' => NULL,
      'label' => NULL,
      'utm_set' => NULL,
      'target_entity_type' => NULL,
      'target_entity_id' => NULL,
      'utm' => array_fill_keys(static::UTM_KEYS, NULL),
    ];
  }

  /**
   * Gets an empty result.
   */
  protected function emptyResult(): array {
    return [
      'rows' => [],
      'summary' => [],
      'totals' => ['raw_count' => 0, 'human_count' => 0, 'unique_count' => 0],
    ];
  }

}
