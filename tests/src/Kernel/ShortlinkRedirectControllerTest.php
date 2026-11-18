<?php

declare(strict_types=1);

namespace Drupal\Tests\shortlink_manager\Kernel;

use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\entity_test\Entity\EntityTest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests ShortlinkRedirectController destinations, status codes and counters.
 *
 * @group shortlink_manager
 */
#[Group('shortlink_manager')]
#[RunTestsInSeparateProcesses]
final class ShortlinkRedirectControllerTest extends ShortlinkKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Asserts that following a slug results in a 404.
   */
  private function assertNotFound(string $slug = 'abc123', ?int $time = NULL, string $user_agent = self::BROWSER): void {
    try {
      $this->follow($slug, $time, $user_agent);
      $this->fail("Expected a 404 for $slug.");
    }
    catch (NotFoundHttpException) {
      $this->addToAssertionCount(1);
    }
  }

  /**
   * Tests an external destination override.
   */
  public function testExternalDestination(): void {
    $this->createShortlink();
    $response = $this->follow();
    $this->assertInstanceOf(TrustedRedirectResponse::class, $response);
    $this->assertSame('https://example.com/post', $response->getTargetUrl());
    $this->assertSame(301, $response->getStatusCode());
    $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
  }

  /**
   * Tests an internal destination override.
   */
  public function testInternalDestination(): void {
    $this->createShortlink(['destination_override' => '/user/login?from=sl']);
    $response = $this->follow();
    $this->assertInstanceOf(RedirectResponse::class, $response);
    $this->assertNotInstanceOf(TrustedRedirectResponse::class, $response);
    $this->assertMatchesRegularExpression('#^https?://[^/]+/user/login\?from=sl$#', $response->getTargetUrl());
  }

  /**
   * Tests a shortlink pointing at an entity instead of a URL.
   */
  public function testEntityDestination(): void {
    $entity = EntityTest::create(['name' => 'Target']);
    $entity->save();
    $this->createShortlink([
      'destination_override' => NULL,
      'target_entity_type' => 'entity_test',
      'target_entity_id' => $entity->id(),
    ]);
    $response = $this->follow();
    $this->assertStringEndsWith('/entity_test/' . $entity->id(), $response->getTargetUrl());

    // A missing target entity is a 404, not an error.
    $this->createShortlink([
      'path' => 'go/gone',
      'destination_override' => NULL,
      'target_entity_type' => 'entity_test',
      'target_entity_id' => 999,
    ]);
    $this->assertNotFound('gone');
  }

  /**
   * Data provider for testStatusCodes().
   */
  public static function statusCodeProvider(): array {
    return [
      'permanent' => [301, 301],
      'temporary' => [307, 307],
      'permanent, method preserved' => [308, 308],
      'unset falls back to 301' => [NULL, 301],
    ];
  }

  /**
   * Tests the configured redirect status code.
   */
  #[DataProvider('statusCodeProvider')]
  public function testStatusCodes(?int $configured, int $expected): void {
    $this->config('shortlink_manager.settings')->set('redirect_status', $configured)->save();
    $this->createShortlink();
    $this->assertSame($expected, $this->follow()->getStatusCode());

    // Internal redirects use the same code.
    $this->createShortlink(['path' => 'go/internal', 'destination_override' => '/user/login']);
    $this->assertSame($expected, $this->follow('internal')->getStatusCode());
  }

  /**
   * Tests unknown, disabled and wrong-prefix slugs.
   */
  public function testNotFound(): void {
    $this->assertNotFound('nope');

    $shortlink = $this->createShortlink();
    $shortlink->set('status', FALSE)->save();
    $this->assertNotFound();
    // A disabled link records no click.
    $this->assertSame(0, (int) $this->reload($shortlink)->get('click_count')->value);

    // The slug is looked up under the configured prefix.
    $shortlink->set('status', TRUE)->save();
    $this->config('shortlink_manager.settings')->set('path_prefix', 's')->save();
    $this->assertNotFound();
    $this->createShortlink(['path' => 's/abc123', 'destination_override' => 'https://example.com/s']);
    $this->assertSame('https://example.com/s', $this->follow()->getTargetUrl());
  }

  /**
   * Tests that each redirect increments clicks and sets last_accessed.
   */
  public function testClickTracking(): void {
    $shortlink = $this->createShortlink();
    $this->assertSame(0, (int) $shortlink->get('click_count')->value);
    $this->assertEmpty($shortlink->get('last_accessed')->value);

    $this->follow('abc123', static::NOW);
    $shortlink = $this->reload($shortlink);
    $this->assertSame(1, (int) $shortlink->get('click_count')->value);
    $this->assertSame(static::NOW, (int) $shortlink->get('last_accessed')->value);

    $this->follow('abc123', static::NOW + 60);
    $this->follow('abc123', static::NOW + 120);
    $shortlink = $this->reload($shortlink);
    $this->assertSame(3, (int) $shortlink->get('click_count')->value);
    $this->assertSame(static::NOW + 120, (int) $shortlink->get('last_accessed')->value);

    // Every redirect writes one row to the click log.
    $logged = $this->container->get('database')->select('shortlink_clicks', 'sc')
      ->condition('shortlink_id', $shortlink->id())
      ->countQuery()->execute()->fetchField();
    $this->assertSame(3, (int) $logged);
  }

  /**
   * Tests that a request ending in a 404 records no click anywhere.
   */
  public function testNotFoundRecordsNoClick(): void {
    $entity = EntityTest::create(['name' => 'Target']);
    $entity->save();
    $shortlink = $this->createShortlink([
      'destination_override' => NULL,
      'target_entity_type' => 'entity_test',
      'target_entity_id' => $entity->id(),
    ]);
    // One real click first, so the counters have something to protect.
    $this->follow('abc123', static::NOW - 86400);

    // The target is deleted out from under the link (an orphaned shortlink).
    // Delete via the database so the cascading entity_delete hook does not
    // remove the shortlink too.
    $this->container->get('database')->delete('entity_test')
      ->condition('id', $entity->id())
      ->execute();
    $this->container->get('entity_type.manager')->getStorage('entity_test')->resetCache();

    // Humans, bots and repeats all get a 404 and change nothing.
    $this->assertNotFound();
    $this->assertNotFound('abc123', static::NOW + 60, 'Twitterbot/1.0');
    $this->assertNotFound();

    $shortlink = $this->reload($shortlink);
    $this->assertSame(1, (int) $shortlink->get('click_count')->value);
    $this->assertSame(1, (int) $shortlink->get('human_click_count')->value);
    $this->assertSame(1, (int) $shortlink->get('unique_click_count')->value);
    $this->assertSame(static::NOW - 86400, (int) $shortlink->get('last_accessed')->value);
    $this->assertTrue($shortlink->isEnabled());

    $database = $this->container->get('database');
    $logged = $database->select('shortlink_clicks', 'sc')
      ->condition('shortlink_id', $shortlink->id())
      ->countQuery()->execute()->fetchField();
    $this->assertSame(1, (int) $logged);

    // The daily rollup only sees the real click.
    $this->container->get('shortlink_manager.click_tracker')->rollupCompletedDays();
    $raw = $database->select('shortlink_clicks_daily', 'd')
      ->condition('shortlink_id', $shortlink->id());
    $raw->addExpression('SUM(raw_count)', 'total');
    $this->assertSame(1, (int) $raw->execute()->fetchField());

    // A link whose target entity type no longer exists is a 404 too.
    $this->createShortlink([
      'path' => 'go/notype',
      'destination_override' => NULL,
      'target_entity_type' => 'no_such_type',
      'target_entity_id' => 1,
    ]);
    $this->assertNotFound('notype');
    $this->assertSame(1, (int) $database->select('shortlink_clicks', 'sc')->countQuery()->execute()->fetchField());
  }

}
