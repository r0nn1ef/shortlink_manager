<?php

declare(strict_types=1);

namespace Drupal\Tests\shortlink_manager\Kernel;

use Drupal\shortlink_manager\Entity\UtmSet;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests UTM parameters, token replacement and destination URL construction.
 *
 * @group shortlink_manager
 */
#[Group('shortlink_manager')]
#[RunTestsInSeparateProcesses]
final class ShortlinkUtmTest extends ShortlinkKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Creates a UTM set.
   */
  private function createUtmSet(array $values): UtmSet {
    $utm_set = UtmSet::create($values + [
      'id' => 'newsletter',
      'label' => 'Newsletter',
      'status' => TRUE,
    ]);
    $utm_set->save();
    return $utm_set;
  }

  /**
   * Follows the default link and returns its destination's query.
   */
  private function followQuery(string $slug = 'abc123'): array {
    $url = $this->follow($slug)->getTargetUrl();
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    return $query;
  }

  /**
   * Tests that UTM values are appended and normalised.
   */
  public function testUtmParametersApplied(): void {
    $this->createUtmSet([
      'utm_source' => 'newsletter',
      'utm_medium' => 'Email',
      'utm_campaign' => 'Spring Sale -- 2026!',
      'custom_parameters' => ['ref: Partner Site', 'malformed-no-colon'],
    ]);
    $this->createShortlink(['utm_set' => 'newsletter']);

    $url = $this->follow()->getTargetUrl();
    $this->assertStringStartsWith('https://example.com/post?', $url);
    $query = $this->followQuery();
    $this->assertSame('newsletter', $query['utm_source']);
    // Values are lowercased, non [a-z0-9_] runs become one underscore, and
    // leading/trailing underscores are trimmed.
    $this->assertSame('email', $query['utm_medium']);
    $this->assertSame('spring_sale_2026', $query['utm_campaign']);
    // Custom "key:value" parameters are added; malformed ones are dropped.
    $this->assertSame('partner_site', $query['ref']);
    $this->assertArrayNotHasKey('malformed-no-colon', $query);
  }

  /**
   * Tests that a link without a UTM set keeps its destination untouched.
   */
  public function testNoUtmSet(): void {
    $this->createShortlink(['destination_override' => 'https://example.com/post?a=1']);
    $this->assertSame('https://example.com/post?a=1', $this->follow()->getTargetUrl());
  }

  /**
   * Tests merging with a query string already on the destination.
   */
  public function testMergeWithExistingQuery(): void {
    $this->createUtmSet(['utm_source' => 'newsletter', 'utm_medium' => 'email']);
    $this->createShortlink([
      'destination_override' => 'https://example.com/post?ref=abc&utm_source=old#section',
      'utm_set' => 'newsletter',
    ]);

    $url = $this->follow()->getTargetUrl();
    $this->assertStringEndsWith('#section', $url);
    $query = $this->followQuery();
    // Existing parameters are kept; the UTM set wins on conflicts.
    $this->assertSame('abc', $query['ref']);
    $this->assertSame('newsletter', $query['utm_source']);
    $this->assertSame('email', $query['utm_medium']);
  }

  /**
   * Tests token replacement in UTM values for an entity-targeted link.
   */
  public function testTokensInUtmValues(): void {
    $account = User::create(['name' => 'Jane Doe', 'status' => 1]);
    $account->save();
    $this->createUtmSet([
      'utm_source' => '[site:name]',
      'utm_campaign' => '[user:account-name]',
      'custom_parameters' => ['uid:[user:uid]'],
    ]);
    $this->createShortlink([
      'destination_override' => NULL,
      'target_entity_type' => 'user',
      'target_entity_id' => $account->id(),
      'utm_set' => 'newsletter',
    ]);

    $url = $this->follow()->getTargetUrl();
    $this->assertStringContainsString('/user/' . $account->id() . '?', $url);
    $query = $this->followQuery();
    $this->assertSame('testsite', $query['utm_source']);
    $this->assertSame('jane_doe', $query['utm_campaign']);
    $this->assertSame((string) $account->id(), $query['uid']);
  }

  /**
   * Tests token replacement in the destination override itself.
   */
  public function testTokensInDestinationOverride(): void {
    $this->createShortlink(['destination_override' => 'https://example.com/[site:name]/post']);
    $this->assertSame('https://example.com/testsite/post', $this->follow()->getTargetUrl());
  }

  /**
   * Tests [shortlink:id] as a GA4 utm_id on an override link.
   */
  public function testShortlinkIdTokenInUtmValues(): void {
    $this->createUtmSet([
      'utm_source' => 'linkedin',
      'utm_content' => '[shortlink:path]',
      'custom_parameters' => ['utm_id:[shortlink:id]', 'sl:[shortlink:label]'],
    ]);
    // A second link first, so the ID under test is not trivially 1.
    $this->createShortlink(['path' => 'go/other']);
    $shortlink = $this->createShortlink(['label' => 'Spring Post', 'utm_set' => 'newsletter']);
    $this->assertGreaterThan(1, (int) $shortlink->id());

    $query = $this->followQuery();
    // The numeric ID passes through the sanitizer unchanged.
    $this->assertSame((string) $shortlink->id(), $query['utm_id']);
    // The other [shortlink:*] tokens resolve against the real entity too.
    $this->assertSame('go_abc123', $query['utm_content']);
    $this->assertSame('spring_post', $query['sl']);
    $this->assertSame('linkedin', $query['utm_source']);
  }

  /**
   * Tests [shortlink:id] alongside entity tokens on an entity-targeted link.
   */
  public function testShortlinkIdTokenWithEntityTarget(): void {
    $account = User::create(['name' => 'Jane Doe', 'status' => 1]);
    $account->save();
    $this->createUtmSet([
      'utm_campaign' => '[user:account-name]',
      'custom_parameters' => ['utm_id:[shortlink:id]'],
    ]);
    $shortlink = $this->createShortlink([
      'destination_override' => NULL,
      'target_entity_type' => 'user',
      'target_entity_id' => $account->id(),
      'utm_set' => 'newsletter',
    ]);

    $query = $this->followQuery();
    $this->assertSame((string) $shortlink->id(), $query['utm_id']);
    $this->assertSame('jane_doe', $query['utm_campaign']);
  }

  /**
   * Tests [shortlink:*] tokens in the destination override.
   */
  public function testShortlinkTokensInDestinationOverride(): void {
    $shortlink = $this->createShortlink([
      'destination_override' => 'https://example.com/post?sl=[shortlink:id]&p=[shortlink:path]',
    ]);
    $this->assertSame(
      'https://example.com/post?sl=' . $shortlink->id() . '&p=go/abc123',
      $this->follow()->getTargetUrl(),
    );
  }

  /**
   * Tests the [shortlink:id] token directly and chained from its target.
   */
  public function testShortlinkIdToken(): void {
    $this->config('shortlink_manager.settings')->set('available_entity_types', ['user'])->save();
    $account = User::create(['name' => 'Jane Doe', 'status' => 1]);
    $account->save();
    $shortlink = $this->createShortlink([
      'destination_override' => NULL,
      'target_entity_type' => 'user',
      'target_entity_id' => $account->id(),
    ]);
    $token = $this->container->get('token');

    $info = $token->getInfo();
    $this->assertArrayHasKey('id', $info['tokens']['shortlink']);
    $this->assertSame((string) $shortlink->id(), $token->replace('[shortlink:id]', ['shortlink' => $shortlink]));
    $this->assertSame((string) $shortlink->id(), $token->replace('[user:shortlink:id]', ['user' => $account]));
  }

  /**
   * Tests how a UTM set builds its parameter list.
   */
  public function testUtmSetParameters(): void {
    $utm_set = $this->createUtmSet([
      'utm_source' => 'a',
      'utm_medium' => 'b',
      'utm_campaign' => 'c',
      'utm_term' => 'd',
      'utm_content' => 'e',
      'custom_parameters' => ['x:1', 'y: two:parts '],
    ]);
    $this->assertSame([
      'utm_source' => 'a',
      'utm_medium' => 'b',
      'utm_campaign' => 'c',
      'utm_term' => 'd',
      'utm_content' => 'e',
      'x' => '1',
      // Only the first colon splits key and value.
      'y' => 'two:parts',
    ], $utm_set->getUtmParameters());
  }

}
