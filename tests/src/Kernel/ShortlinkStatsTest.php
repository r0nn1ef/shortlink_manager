<?php

declare(strict_types=1);

namespace Drupal\Tests\shortlink_manager\Kernel;

use Drupal\shortlink_manager\Entity\UtmSet;
use Drupal\shortlink_manager\ShortlinkClickTracker;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Tests the daily click statistics service and its REST resource.
 *
 * @group shortlink_manager
 */
#[Group('shortlink_manager')]
#[RunTestsInSeparateProcesses]
final class ShortlinkStatsTest extends ShortlinkKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // NOW is 2025-11-17 11:20 in Chicago, so "yesterday" is 2025-11-16.
    $this->config('system.date')->set('timezone.default', 'America/Chicago')->save();
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Creates a UTM set.
   */
  private function createUtmSet(string $id, array $values): UtmSet {
    $utm_set = UtmSet::create($values + [
      'id' => $id,
      'label' => $id,
      'status' => TRUE,
    ]);
    $utm_set->save();
    return $utm_set;
  }

  /**
   * Writes a daily totals row.
   */
  private function addDaily(int $shortlink_id, string $day, int $raw, int $human, int $unique): void {
    $this->container->get('database')->insert('shortlink_clicks_daily')
      ->fields([
        'shortlink_id' => $shortlink_id,
        'day' => $day,
        'raw_count' => $raw,
        'human_count' => $human,
        'unique_count' => $unique,
      ])
      ->execute();
  }

  /**
   * Calls the REST resource's GET handler with a query string.
   */
  private function callResource(array $query = []): array {
    $request = Request::create('/api/shortlink/stats', 'GET', $query);
    $this->container->get('request_stack')->push($request);
    try {
      /** @var \Drupal\shortlink_manager\Plugin\rest\resource\ShortlinkStatsResource $resource */
      $resource = $this->container->get('plugin.manager.rest')->createInstance('shortlink_stats');
      return $resource->get()->getResponseData();
    }
    finally {
      $this->container->get('request_stack')->pop();
    }
  }

  /**
   * Creates two social links and one newsletter link with daily totals.
   *
   * @return array
   *   The shortlinks keyed by name.
   */
  private function seed(): array {
    $account = User::create(['name' => 'Jane Doe', 'status' => 1]);
    $account->save();
    $this->createUtmSet('linkedin', [
      'utm_source' => 'LinkedIn',
      'utm_medium' => 'social',
      'utm_campaign' => '[user:account-name]',
      'custom_parameters' => ['utm_id:[shortlink:id]', 'ref:partner'],
    ]);
    $this->createUtmSet('newsletter', ['utm_source' => 'newsletter', 'utm_medium' => 'email']);

    $links['post'] = $this->createShortlink([
      'path' => 'go/post',
      'destination_override' => NULL,
      'target_entity_type' => 'user',
      'target_entity_id' => $account->id(),
      'utm_set' => 'linkedin',
    ]);
    $links['promo'] = $this->createShortlink(['path' => 'go/promo', 'utm_set' => 'linkedin']);
    $links['mail'] = $this->createShortlink(['path' => 'go/mail', 'utm_set' => 'newsletter']);

    $this->addDaily((int) $links['post']->id(), '2025-11-15', 10, 6, 4);
    $this->addDaily((int) $links['post']->id(), '2025-11-16', 5, 3, 2);
    $this->addDaily((int) $links['promo']->id(), '2025-11-16', 4, 4, 4);
    $this->addDaily((int) $links['mail']->id(), '2025-11-16', 9, 8, 7);
    // Outside the default 28-day window (2025-10-20 to 2025-11-16).
    $this->addDaily((int) $links['mail']->id(), '2025-10-19', 100, 100, 100);
    return $links;
  }

  /**
   * Tests rows, UTM values as sent, the per-source summary and totals.
   */
  public function testDailyStats(): void {
    $links = $this->seed();
    $stats = $this->container->get('shortlink_manager.stats');

    $result = $stats->getDailyStats('2025-10-20', '2025-11-16');
    $this->assertCount(4, $result['rows']);
    $this->assertSame(['raw_count' => 28, 'human_count' => 21, 'unique_count' => 17], $result['totals']);

    // Rows are ordered by day, then shortlink ID.
    $first = $result['rows'][0];
    $this->assertSame('2025-11-15', $first['day']);
    $this->assertSame((int) $links['post']->id(), $first['shortlink_id']);
    $this->assertSame('go/post', $first['path']);
    $this->assertSame('linkedin', $first['utm_set']);
    $this->assertSame('user', $first['target_entity_type']);
    $this->assertSame([10, 6, 4], [$first['raw_count'], $first['human_count'], $first['unique_count']]);
    // UTM values are the sanitized values a redirect sends: tokens use the
    // target entity and the shortlink. Non-UTM parameters are not reported.
    $this->assertSame([
      'utm_source' => 'linkedin',
      'utm_medium' => 'social',
      'utm_campaign' => 'jane_doe',
      'utm_term' => NULL,
      'utm_content' => NULL,
      'utm_id' => (string) $links['post']->id(),
    ], $first['utm']);

    // The override link gets no target entity, so its user token clears.
    $promo = $result['rows'][2];
    $this->assertSame((int) $links['promo']->id(), $promo['shortlink_id']);
    $this->assertNull($promo['target_entity_type']);
    $this->assertNull($promo['utm']['utm_campaign']);
    $this->assertSame((string) $links['promo']->id(), $promo['utm']['utm_id']);

    // Summary per utm_source, most human clicks first.
    $this->assertSame([
      [
        'utm_source' => 'linkedin',
        'shortlinks' => 2,
        'raw_count' => 19,
        'human_count' => 13,
        'unique_count' => 10,
      ],
      [
        'utm_source' => 'newsletter',
        'shortlinks' => 1,
        'raw_count' => 9,
        'human_count' => 8,
        'unique_count' => 7,
      ],
    ], $result['summary']);

    $this->assertSame('2025-10-19', $stats->getFirstDay());
  }

  /**
   * Tests each filter.
   */
  public function testFilters(): void {
    $links = $this->seed();
    $stats = $this->container->get('shortlink_manager.stats');
    $ids = static fn(array $result) => array_values(array_unique(array_column($result['rows'], 'shortlink_id')));

    $result = $stats->getDailyStats('2025-10-20', '2025-11-16', ['utm_source' => 'linkedin']);
    $this->assertSame([(int) $links['post']->id(), (int) $links['promo']->id()], $ids($result));
    $this->assertCount(1, $result['summary']);

    $result = $stats->getDailyStats('2025-10-20', '2025-11-16', ['utm_set' => 'newsletter']);
    $this->assertSame([(int) $links['mail']->id()], $ids($result));

    $result = $stats->getDailyStats('2025-10-20', '2025-11-16', ['target_entity_type' => 'user']);
    $this->assertSame([(int) $links['post']->id()], $ids($result));

    $result = $stats->getDailyStats('2025-10-20', '2025-11-16', ['shortlink' => (string) $links['promo']->id()]);
    $this->assertSame([(int) $links['promo']->id()], $ids($result));

    // A filter matching no shortlink returns an empty result.
    $result = $stats->getDailyStats('2025-10-20', '2025-11-16', ['utm_set' => 'missing']);
    $this->assertSame([], $result['rows']);
    $this->assertSame(['raw_count' => 0, 'human_count' => 0, 'unique_count' => 0], $result['totals']);
  }

  /**
   * Tests that totals for a deleted shortlink are still reported.
   */
  public function testDeletedShortlink(): void {
    $links = $this->seed();
    $id = (int) $links['mail']->id();
    $links['mail']->delete();

    $result = $this->container->get('shortlink_manager.stats')->getDailyStats('2025-11-16', '2025-11-16');
    $rows = array_values(array_filter($result['rows'], static fn(array $row) => $row['shortlink_id'] === $id));
    $this->assertCount(1, $rows);
    $this->assertNull($rows[0]['path']);
    $this->assertNull($rows[0]['utm']['utm_source']);
    // Its clicks are summed under a NULL utm_source.
    $this->assertSame([NULL, 'linkedin'], array_column($result['summary'], 'utm_source'));
    $this->assertSame(8, $result['summary'][0]['human_count']);
  }

  /**
   * Tests the resource's default window and response metadata.
   */
  public function testResourceDefaults(): void {
    $this->seed();
    \Drupal::state()->set(ShortlinkClickTracker::STATE_LAST_ROLLUP_DAY, (new \DateTimeImmutable('2025-11-16', new \DateTimeZone('America/Chicago')))->getTimestamp());

    $data = $this->callResource();
    $this->assertSame('2025-10-20', $data['from']);
    $this->assertSame('2025-11-16', $data['to']);
    $this->assertSame('America/Chicago', $data['timezone']);
    $this->assertSame('2025-11-16', $data['rolled_up_through']);
    $this->assertSame('2025-10-19', $data['data_starts']);
    $this->assertSame([], $data['filters']);
    $this->assertCount(4, $data['rows']);
    $this->assertSame(28, $data['totals']['raw_count']);

    $data = $this->callResource(['from' => '2025-10-19', 'to' => '2025-10-19', 'utm_source' => 'newsletter']);
    $this->assertSame(['utm_source' => 'newsletter'], $data['filters']);
    $this->assertSame(100, $data['totals']['human_count']);
  }

  /**
   * Tests that bad dates are rejected.
   */
  public function testResourceValidation(): void {
    $bad = [
      ['from' => '2025-11-31'],
      ['to' => '11/16/2025'],
      ['from' => '2025-11-16', 'to' => '2025-11-15'],
      ['from' => '2024-01-01', 'to' => '2025-11-16'],
    ];
    foreach ($bad as $query) {
      try {
        $this->callResource($query);
        $this->fail('Expected a 400 for ' . http_build_query($query));
      }
      catch (BadRequestHttpException) {
        // Expected.
      }
    }
    // Exactly 366 days is allowed.
    $data = $this->callResource(['from' => '2024-11-16', 'to' => '2025-11-16']);
    $this->assertSame('2024-11-16', $data['from']);
  }

}
