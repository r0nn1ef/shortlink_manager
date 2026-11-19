<?php

declare(strict_types=1);

namespace Drupal\Tests\shortlink_manager\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\rest\Entity\RestResourceConfig;
use Drupal\rest\RestResourceConfigInterface;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the shortlink stats REST resource over HTTP.
 *
 * @group shortlink_manager
 */
#[Group('shortlink_manager')]
#[RunTestsInSeparateProcesses]
final class ShortlinkStatsResourceTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['shortlink_manager', 'basic_auth'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests access control and the JSON response.
   */
  public function testResource(): void {
    RestResourceConfig::create([
      'id' => 'shortlink_stats',
      'plugin_id' => 'shortlink_stats',
      'granularity' => RestResourceConfigInterface::RESOURCE_GRANULARITY,
      'configuration' => [
        'methods' => ['GET'],
        'formats' => ['json'],
        'authentication' => ['basic_auth'],
      ],
    ])->save();
    $this->container->get('router.builder')->rebuild();

    $this->container->get('database')->insert('shortlink_clicks_daily')
      ->fields([
        'shortlink_id' => 1,
        'day' => '2025-11-16',
        'raw_count' => 5,
        'human_count' => 3,
        'unique_count' => 2,
      ])
      ->execute();

    $client = $this->getHttpClient();
    $url = $this->buildUrl('/api/shortlink/stats', [
      'query' => ['_format' => 'json', 'from' => '2025-11-16', 'to' => '2025-11-16'],
    ]);

    $denied = $this->drupalCreateUser([]);
    $response = $client->get($url, [
      'auth' => [$denied->getAccountName(), $denied->passRaw],
      'http_errors' => FALSE,
    ]);
    $this->assertSame(403, $response->getStatusCode());

    $allowed = $this->drupalCreateUser(['restful get shortlink_stats']);
    $response = $client->get($url, [
      'auth' => [$allowed->getAccountName(), $allowed->passRaw],
      'http_errors' => FALSE,
    ]);
    $this->assertSame(200, $response->getStatusCode());
    $data = Json::decode((string) $response->getBody());
    $this->assertSame('2025-11-16', $data['from']);
    $this->assertSame(['raw_count' => 5, 'human_count' => 3, 'unique_count' => 2], $data['totals']);
    // Only aggregates: no click log fields leak into the response.
    $this->assertStringNotContainsString('ip_hash', (string) $response->getBody());
    $this->assertStringNotContainsString('user_agent', (string) $response->getBody());

    $response = $client->get($this->buildUrl('/api/shortlink/stats', [
      'query' => ['_format' => 'json', 'from' => 'nope'],
    ]), [
      'auth' => [$allowed->getAccountName(), $allowed->passRaw],
      'http_errors' => FALSE,
    ]);
    $this->assertSame(400, $response->getStatusCode());
  }

}
