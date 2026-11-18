<?php

declare(strict_types=1);

namespace Drupal\Tests\shortlink_manager\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\shortlink_manager\Entity\Shortlink;
use Drupal\shortlink_manager\ShortlinkClickTracker;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests updates 10513-10516 and the click log post-update on a 10512 site.
 *
 * @group shortlink_manager
 */
#[Group('shortlink_manager')]
#[RunTestsInSeparateProcesses]
final class ClickStatsUpdatePathTest extends KernelTestBase {

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
   * Tests the update path from the pre-INFRA-109 schema.
   */
  public function testUpdatePath(): void {
    $this->installEntitySchema('shortlink');
    $this->installSchema('shortlink_manager', ['shortlink_clicks']);
    $this->installConfig(['system']);
    $this->config('system.date')->set('timezone.default', 'UTC')->save();

    // Simulate a site on 10512: settings without click_tracking, no
    // classification columns, no daily table, no new entity fields.
    $this->config('shortlink_manager.settings')->setData([
      'path_prefix' => 'go',
      'path_length' => 6,
      'redirect_status' => 301,
      'expiration' => ['click_log_retention_days' => 90],
    ])->save();
    $db = $this->container->get('database');
    $schema = $db->schema();
    $schema->dropIndex('shortlink_clicks', 'shortlink_ip_timestamp');
    $schema->dropField('shortlink_clicks', 'is_bot');
    $schema->dropField('shortlink_clicks', 'is_unique');
    $update_manager = $this->container->get('entity.definition_update_manager');

    $shortlink = Shortlink::create([
      'label' => 'Old',
      'path' => 'go/old123',
      'destination_override' => 'https://example.com',
      'click_count' => 500,
    ]);
    $shortlink->save();
    $id = (int) $shortlink->id();

    foreach (['human_click_count', 'unique_click_count'] as $field_name) {
      $update_manager->uninstallFieldStorageDefinition($update_manager->getFieldStorageDefinition($field_name, 'shortlink'));
    }

    $day = 1_763_337_600;
    $rows = [
      [$day + 3600, 'Mozilla/5.0 Firefox/133.0', 'a'],
      [$day + 3700, 'Mozilla/5.0 Firefox/133.0', 'a'],
      [$day + 3800, 'facebookexternalhit/1.1', 'b'],
      [$day + 90000, 'Mozilla/5.0 Firefox/133.0', 'c'],
    ];
    foreach ($rows as [$ts, $ua, $ip]) {
      $db->insert('shortlink_clicks')->fields([
        'shortlink_id' => $id,
        'timestamp' => $ts,
        'user_agent' => $ua,
        'ip_hash' => hash('sha256', $ip),
      ])->execute();
    }

    // Run the updates.
    $this->container->get('module_handler')->loadInclude('shortlink_manager', 'install');
    $this->container->get('module_handler')->loadInclude('shortlink_manager', 'php', 'shortlink_manager.post_update');
    shortlink_manager_update_10513();
    shortlink_manager_update_10514();
    shortlink_manager_update_10515();
    shortlink_manager_update_10516();
    // Rebuild so the container sees the new schema.
    $this->container->get('entity_type.manager')->clearCachedDefinitions();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();

    $this->assertSame(ShortlinkClickTracker::DEFAULT_BOT_PATTERNS, $this->config('shortlink_manager.settings')->get('click_tracking.bot_user_agent_patterns'));
    $this->assertSame(30, $this->config('shortlink_manager.settings')->get('click_tracking.unique_click_window_minutes'));
    $this->assertTrue($schema->fieldExists('shortlink_clicks', 'is_bot'));
    $this->assertTrue($schema->indexExists('shortlink_clicks', 'shortlink_ip_timestamp'));
    $this->assertTrue($schema->tableExists('shortlink_clicks_daily'));
    $this->assertArrayNotHasKey('shortlink', $update_manager->getChangeSummary(), 'Shortlink entity definitions are up to date.');

    // Today is the day after the last click.
    $this->container->get('request_stack')->getCurrentRequest()->server->set('REQUEST_TIME', $day + 86400 * 2 + 100);

    $sandbox = [];
    do {
      $message = shortlink_manager_post_update_classify_click_log($sandbox);
    } while ($sandbox['#finished'] < 1);
    $this->assertStringContainsString('Classified 4 logged clicks (1 bots, 2 unique)', $message);

    $storage = $this->container->get('entity_type.manager')->getStorage('shortlink');
    $storage->resetCache();
    $shortlink = $storage->load($id);
    $this->assertSame(500, (int) $shortlink->get('click_count')->value);
    $this->assertSame(3, (int) $shortlink->get('human_click_count')->value);
    $this->assertSame(2, (int) $shortlink->get('unique_click_count')->value);

    $daily = $db->select('shortlink_clicks_daily', 'd')->fields('d')->orderBy('day')->execute()->fetchAll();
    $this->assertCount(2, $daily);
    $this->assertEquals([3, 2, 1], [$daily[0]->raw_count, $daily[0]->human_count, $daily[0]->unique_count]);
    $this->assertEquals([1, 1, 1], [$daily[1]->raw_count, $daily[1]->human_count, $daily[1]->unique_count]);
  }

}
