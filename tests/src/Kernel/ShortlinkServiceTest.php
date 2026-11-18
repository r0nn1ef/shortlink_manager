<?php

declare(strict_types=1);

namespace Drupal\Tests\shortlink_manager\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\shortlink_manager\ShortlinkManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the shortlink_manager.shortlink_manager service.
 *
 * @group shortlink_manager
 */
#[Group('shortlink_manager')]
#[RunTestsInSeparateProcesses]
final class ShortlinkServiceTest extends ShortlinkKernelTestBase {

  /**
   * The characters a generated slug may contain.
   *
   * Unambiguous letters and digits (no 0, i, l, o, I, L, O) plus - and _.
   */
  private const SLUG_CHARACTERS = '[1-9a-hjkmnp-zA-HJKMNP-Z_-]';

  /**
   * The service under test.
   */
  private ShortlinkManager $manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->manager = $this->container->get('shortlink_manager.shortlink_manager');
  }

  /**
   * Builds a ShortlinkManager whose uniqueness check is replaced.
   *
   * @param callable $path_exists
   *   Called with ($path, $parent_check) for every candidate path, where
   *   $parent_check is the real ShortlinkManager::pathExists().
   */
  private function managerWithPathCheck(callable $path_exists): ShortlinkManager {
    $c = $this->container;
    return new class(
      $c->get('config.factory'),
      $c->get('entity_type.manager'),
      $c->get('messenger'),
      $c->get('path_alias.manager'),
      $c->get('path.validator'),
      $c->get('logger.channel.shortlink_manager'),
      $path_exists,
    ) extends ShortlinkManager {

      /**
       * The replacement uniqueness check.
       *
       * @var callable
       */
      private $check;

      /**
       * Every candidate path passed to pathExists().
       *
       * @var string[]
       */
      public array $candidates = [];

      public function __construct($config_factory, $entity_type_manager, $messenger, $alias_manager, $path_validator, $logger, callable $check) {
        parent::__construct($config_factory, $entity_type_manager, $messenger, $alias_manager, $path_validator, $logger);
        $this->check = $check;
      }

      /**
       * Records the candidate and defers to the replacement check.
       */
      protected function pathExists(string $path): bool {
        $this->candidates[] = $path;
        return ($this->check)($path, fn(string $p): bool => parent::pathExists($p));
      }

      /**
       * Exposes the real uniqueness check.
       */
      public function realPathExists(string $path): bool {
        return parent::pathExists($path);
      }

    };
  }

  /**
   * Tests the generated path format and configured length.
   */
  public function testGeneratedPathFormat(): void {
    $path = $this->manager->generateShortlinkPath();
    $this->assertMatchesRegularExpression('#^go/' . self::SLUG_CHARACTERS . '{6}$#', $path);

    // The configured length and prefix are used.
    $this->config('shortlink_manager.settings')
      ->set('path_length', 9)
      ->set('path_prefix', 's')
      ->save();
    $this->assertMatchesRegularExpression('#^s/' . self::SLUG_CHARACTERS . '{9}$#', $this->manager->generateShortlinkPath());

    // An explicit length wins over the configured one.
    $this->assertMatchesRegularExpression('#^s/' . self::SLUG_CHARACTERS . '{12}$#', $this->manager->generateShortlinkPath(12));

    // Lengths below 4 are raised to 4, from config or from the argument.
    $this->config('shortlink_manager.settings')->set('path_length', 2)->save();
    $this->assertMatchesRegularExpression('#^s/' . self::SLUG_CHARACTERS . '{4}$#', $this->manager->generateShortlinkPath());
    $this->assertMatchesRegularExpression('#^s/' . self::SLUG_CHARACTERS . '{4}$#', $this->manager->generateShortlinkPath(3));
  }

  /**
   * Tests that generated paths are unique and avoid existing shortlinks.
   */
  public function testGeneratedPathsAreUnique(): void {
    $paths = [];
    for ($i = 0; $i < 200; $i++) {
      $path = $this->manager->generateShortlinkPath(4);
      $this->createShortlink(['path' => $path]);
      $paths[] = $path;
    }
    $this->assertCount(200, array_unique($paths));
  }

  /**
   * Tests the uniqueness check used by path generation.
   *
   * The issue asked for a test of pathExists(). It is a protected helper, so
   * it is exercised through a subclass rather than made public.
   */
  public function testPathExists(): void {
    $this->createShortlink(['path' => 'go/taken1']);
    $manager = $this->managerWithPathCheck(fn(string $path, callable $parent): bool => $parent($path));
    $this->assertTrue($manager->realPathExists('go/taken1'));
    $this->assertFalse($manager->realPathExists('go/free99'));
    // Matching is on the full path, not just the slug.
    $this->assertFalse($manager->realPathExists('s/taken1'));
  }

  /**
   * Tests that a colliding candidate is skipped and a new one generated.
   */
  public function testCollisionRetries(): void {
    $collisions = 3;
    $manager = $this->managerWithPathCheck(function (string $path, callable $parent) use (&$collisions): bool {
      return $collisions-- > 0 || $parent($path);
    });
    $path = $manager->generateShortlinkPath();
    $this->assertCount(4, $manager->candidates);
    $this->assertSame($path, end($manager->candidates));
  }

  /**
   * Tests the safety limit when every candidate collides.
   */
  public function testMaxAttempts(): void {
    $manager = $this->managerWithPathCheck(fn(): bool => TRUE);
    try {
      $manager->generateShortlinkPath();
      $this->fail('Expected a RuntimeException.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('after 100 attempts', $e->getMessage());
    }
    $this->assertCount(100, $manager->candidates);
  }

  /**
   * Tests that the 100th candidate is still accepted when it is free.
   */
  public function testLastAttemptIsChecked(): void {
    $calls = 0;
    $manager = $this->managerWithPathCheck(function () use (&$calls): bool {
      return ++$calls < 100;
    });
    $manager->generateShortlinkPath();
    $this->assertCount(100, $manager->candidates);
  }

  /**
   * Tests vanity path validation.
   */
  public function testValidateCustomPath(): void {
    $this->container->get('router.builder')->rebuild();

    // Valid and free.
    $this->assertSame([], $this->manager->validateCustomPath('spring-sale_2026'));

    // Disallowed characters stop validation immediately.
    foreach (['has space', 'slash/inside', 'dot.ted', 'ümlaut', ''] as $slug) {
      $errors = $this->manager->validateCustomPath($slug);
      $this->assertCount(1, $errors, $slug);
      $this->assertStringContainsString('may only contain', $errors[0]);
    }

    // Taken by another shortlink.
    $existing = $this->createShortlink(['path' => 'go/taken']);
    $errors = $this->manager->validateCustomPath('taken');
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('already exists', $errors[0]);
    // ...but not when editing that same shortlink.
    $this->assertSame([], $this->manager->validateCustomPath('taken', (int) $existing->id()));

    // Collides with a path alias. The alias also resolves to a route, so the
    // route check reports it as well.
    PathAlias::create(['path' => '/user/login', 'alias' => '/go/aliased'])->save();
    $errors = $this->manager->validateCustomPath('aliased');
    $this->assertCount(2, $errors);
    $this->assertStringContainsString('path alias', $errors[0]);
    $this->assertStringContainsString('existing route', $errors[1]);

    // Collides with a route outside the shortlink redirect route.
    $this->config('shortlink_manager.settings')->set('path_prefix', 'user')->save();
    $errors = $this->manager->validateCustomPath('login');
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('existing route', $errors[0]);
  }

  /**
   * Tests looking up and deleting the shortlinks of a target entity.
   */
  public function testEntityShortlinks(): void {
    $kept = EntityTest::create(['name' => 'Kept']);
    $kept->save();
    $deleted = EntityTest::create(['name' => 'Deleted']);
    $deleted->save();

    // Give the target entity a shortlink whose ID differs from its own, and
    // another entity whose ID equals that shortlink's ID, so a lookup on the
    // wrong storage would delete the wrong thing.
    $this->createShortlink(['path' => 'go/pad1']);
    $this->createShortlink(['path' => 'go/pad2']);
    $link_a = $this->createShortlink([
      'path' => 'go/del1',
      'destination_override' => NULL,
      'target_entity_type' => 'entity_test',
      'target_entity_id' => $deleted->id(),
    ]);
    $link_b = $this->createShortlink([
      'path' => 'go/del2',
      'destination_override' => NULL,
      'target_entity_type' => 'entity_test',
      'target_entity_id' => $deleted->id(),
    ]);
    $other = $this->createShortlink([
      'path' => 'go/keep',
      'destination_override' => NULL,
      'target_entity_type' => 'entity_test',
      'target_entity_id' => $kept->id(),
    ]);
    for ($i = 3; $i <= (int) $link_b->id(); $i++) {
      EntityTest::create(['name' => 'Bystander ' . $i])->save();
    }

    $found = $this->manager->getShortlinksForEntity('entity_test', (string) $deleted->id());
    $this->assertEqualsCanonicalizing([$link_a->id(), $link_b->id()], array_keys($found));
    $this->assertSame([], $this->manager->getShortlinksForEntity('entity_test', '999'));

    $entity_count = fn(): int => (int) $this->container->get('entity_type.manager')->getStorage('entity_test')
      ->getQuery()->accessCheck(FALSE)->count()->execute();
    $before = $entity_count();

    // Deleting the target cascades through hook_entity_delete().
    $deleted->delete();

    $this->assertNull($this->reload($link_a));
    $this->assertNull($this->reload($link_b));
    $this->assertNotNull($this->reload($other));
    // Only the deleted entity is gone: no bystander entity was removed.
    $this->assertSame($before - 1, $entity_count());
  }

}
