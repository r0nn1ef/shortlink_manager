<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager\Plugin\rest\resource;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\rest\Attribute\RestResource;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Drupal\shortlink_manager\ShortlinkClickTracker;
use Drupal\shortlink_manager\ShortlinkStats;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Read-only daily shortlink click statistics.
 *
 * GET /api/shortlink/stats?from=Y-m-d&to=Y-m-d returns one row per shortlink
 * per day from the daily totals (raw, human and unique clicks), with each
 * link's UTM parameters as sent, plus a per-utm_source summary. Without
 * dates it covers the 28 complete days before today. Optional filters:
 * shortlink, utm_set, utm_source, target_entity_type, target_entity_id.
 *
 * Access needs the "Access GET on Shortlink click statistics resource"
 * permission (restful get shortlink_stats) and whatever authentication
 * providers the site enables in the resource's REST configuration.
 */
#[RestResource(
  id: 'shortlink_stats',
  label: new TranslatableMarkup('Shortlink click statistics'),
  uri_paths: [
    'canonical' => '/api/shortlink/stats',
  ],
)]
class ShortlinkStatsResource extends ResourceBase {

  /**
   * Days covered when no date range is given.
   */
  public const DEFAULT_DAYS = 28;

  /**
   * Longest range a single request may cover, in days.
   */
  public const MAX_DAYS = 366;

  /**
   * Query parameters accepted as filters.
   */
  protected const FILTERS = [
    'shortlink',
    'utm_set',
    'utm_source',
    'target_entity_type',
    'target_entity_id',
  ];

  /**
   * The stats service.
   */
  protected ShortlinkStats $stats;

  /**
   * The click tracker.
   */
  protected ShortlinkClickTracker $clickTracker;

  /**
   * The request stack.
   */
  protected RequestStack $requestStack;

  /**
   * The time service.
   */
  protected TimeInterface $time;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.channel.shortlink_manager'),
    );
    $instance->stats = $container->get('shortlink_manager.stats');
    $instance->clickTracker = $container->get('shortlink_manager.click_tracker');
    $instance->requestStack = $container->get('request_stack');
    $instance->time = $container->get('datetime.time');
    return $instance;
  }

  /**
   * Responds to GET requests.
   *
   * @return \Drupal\rest\ResourceResponse
   *   The statistics.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   When the dates are malformed, reversed or too far apart.
   */
  public function get(): ResourceResponse {
    $query = $this->requestStack->getCurrentRequest()->query;
    $timezone = $this->clickTracker->getTimezone();

    $to = $query->has('to')
      ? $this->parseDay((string) $query->get('to'), 'to')
      : (new \DateTimeImmutable('@' . $this->time->getRequestTime()))->setTimezone($timezone)->modify('-1 day')->setTime(0, 0);
    $from = $query->has('from')
      ? $this->parseDay((string) $query->get('from'), 'from')
      : $to->modify(sprintf('-%d days', static::DEFAULT_DAYS - 1));

    if ($from > $to) {
      throw new BadRequestHttpException('"from" must not be after "to".');
    }
    if ((int) $from->diff($to)->days + 1 > static::MAX_DAYS) {
      throw new BadRequestHttpException(sprintf('The date range may cover at most %d days.', static::MAX_DAYS));
    }

    $filters = [];
    foreach (static::FILTERS as $filter) {
      if ($query->has($filter) && (string) $query->get($filter) !== '') {
        $filters[$filter] = (string) $query->get($filter);
      }
    }

    $from_day = $from->format('Y-m-d');
    $to_day = $to->format('Y-m-d');
    $result = $this->stats->getDailyStats($from_day, $to_day, $filters);

    $data = [
      'from' => $from_day,
      'to' => $to_day,
      'timezone' => $timezone->getName(),
      'filters' => $filters,
      // Today is never rolled up, and later days wait for cron. Days after
      // this one have no rows yet even if they had clicks.
      'rolled_up_through' => $this->clickTracker->getLastRolledUpDay(),
      // Days before this have no daily totals, so no human/unique counts.
      'data_starts' => $this->stats->getFirstDay(),
    ] + $result;

    $response = new ResourceResponse($data, 200);
    // Totals change on every cron rollup, which invalidates no cache tag.
    $response->addCacheableDependency((new CacheableMetadata())
      ->setCacheMaxAge(0)
      ->addCacheContexts(['url.query_args', 'user.permissions']));
    return $response;
  }

  /**
   * Parses a Y-m-d query parameter as a day in the rollup timezone.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   */
  protected function parseDay(string $value, string $name): \DateTimeImmutable {
    $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $this->clickTracker->getTimezone());
    if (!$day || $day->format('Y-m-d') !== $value) {
      throw new BadRequestHttpException(sprintf('"%s" must be a date in Y-m-d format.', $name));
    }
    return $day;
  }

}
