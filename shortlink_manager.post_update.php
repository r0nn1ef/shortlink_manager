<?php

/**
 * @file
 * Post update functions for the Shortlink Manager module.
 */

declare(strict_types=1);

/**
 * Classify the existing click log, backfill counters and daily totals.
 *
 * Human and unique counts can only be rebuilt from the click log that is
 * still retained, so for older shortlinks they start from the oldest logged
 * click, not from the shortlink's creation. click_count is left untouched.
 */
function shortlink_manager_post_update_classify_click_log(array &$sandbox): string {
  /** @var \Drupal\shortlink_manager\ShortlinkClickTracker $tracker */
  $tracker = \Drupal::service('shortlink_manager.click_tracker');
  $storage = \Drupal::entityTypeManager()->getStorage('shortlink');

  if (!isset($sandbox['ids'])) {
    $sandbox['classified'] = $tracker->reclassifyAll();
    $sandbox['counts'] = $tracker->getLoggedCountsByShortlink();
    $sandbox['ids'] = array_keys($sandbox['counts']);
    $sandbox['total'] = count($sandbox['ids']);
    $sandbox['done'] = 0;
  }

  $batch = array_splice($sandbox['ids'], 0, 50);
  foreach ($storage->loadMultiple($batch) as $id => $shortlink) {
    $counts = $sandbox['counts'][(int) $id];
    $shortlink->set('human_click_count', $counts['human']);
    $shortlink->set('unique_click_count', $counts['unique']);
    // Don't bump the changed timestamp for a data backfill.
    $shortlink->setSyncing(TRUE);
    $shortlink->save();
  }
  $sandbox['done'] += count($batch);
  $sandbox['#finished'] = $sandbox['total'] ? $sandbox['done'] / $sandbox['total'] : 1;

  if ($sandbox['#finished'] < 1) {
    return '';
  }

  $days = $tracker->rollupCompletedDays();
  $c = $sandbox['classified'];
  return (string) t('Classified @total logged clicks (@bots bots, @unique unique), updated counts on @links shortlinks and rolled up @days days.', [
    '@total' => $c['total'],
    '@bots' => $c['bots'],
    '@unique' => $c['unique'],
    '@links' => $sandbox['total'],
    '@days' => $days,
  ]);
}
