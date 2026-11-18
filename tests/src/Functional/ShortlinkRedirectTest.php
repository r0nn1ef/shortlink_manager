<?php

declare(strict_types=1);

namespace Drupal\Tests\shortlink_manager\Functional;

use Drupal\shortlink_manager\Entity\Shortlink;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests shortlink redirects over HTTP on a fully installed site.
 *
 * Detailed destination, UTM and expiration logic is covered by the kernel
 * tests; this test checks the real route, response codes and headers, and
 * that the module installs cleanly with its shipped configuration.
 *
 * @group shortlink_manager
 */
#[Group('shortlink_manager')]
#[RunTestsInSeparateProcesses]
final class ShortlinkRedirectTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['shortlink_manager'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Requests a path without following redirects.
   */
  private function get(string $path): void {
    $this->getSession()->getDriver()->getClient()->followRedirects(FALSE);
    // The redirect body carries a meta refresh, which drupalGet() follows.
    $this->maximumMetaRefreshCount = 0;
    $this->drupalGet($path);
  }

  /**
   * Creates an enabled shortlink to an internal path.
   */
  private function createShortlink(string $path, array $values = []): Shortlink {
    $shortlink = Shortlink::create($values + [
      'label' => $path,
      'path' => $path,
      'destination_override' => '/user/login',
      'status' => TRUE,
    ]);
    $shortlink->save();
    return $shortlink;
  }

  /**
   * Reloads a shortlink from storage.
   */
  private function reload(Shortlink $shortlink): Shortlink {
    $storage = $this->container->get('entity_type.manager')->getStorage('shortlink');
    $storage->resetCache([$shortlink->id()]);
    return $storage->load($shortlink->id());
  }

  /**
   * Tests redirects, status codes, click counting and expiry over HTTP.
   */
  public function testRedirect(): void {
    $shortlink = $this->createShortlink('go/abc123');

    // Anonymous visitors are redirected with the default 301.
    $this->get('go/abc123');
    $this->assertSession()->statusCodeEquals(301);
    $this->assertStringEndsWith('/user/login', $this->getSession()->getResponseHeader('Location'));
    $this->assertSession()->responseHeaderEquals('X-Robots-Tag', 'noindex, nofollow');
    $this->assertSame(1, (int) $this->reload($shortlink)->get('click_count')->value);
    $this->assertNotEmpty($this->reload($shortlink)->get('last_accessed')->value);

    // The configured status code is used.
    foreach ([307, 308, 301] as $code) {
      $this->config('shortlink_manager.settings')->set('redirect_status', $code)->save();
      $this->get('go/abc123');
      $this->assertSession()->statusCodeEquals($code);
    }
    $this->assertSame(4, (int) $this->reload($shortlink)->get('click_count')->value);

    // Unknown and invalid slugs are 404s.
    $this->get('go/unknown');
    $this->assertSession()->statusCodeEquals(404);
    $this->get('go/bad.slug');
    $this->assertSession()->statusCodeEquals(404);

    // An expired link is a 404 and gets disabled.
    $expired = $this->createShortlink('go/old', ['expires_at' => \Drupal::time()->getRequestTime() - 60]);
    $this->get('go/old');
    $this->assertSession()->statusCodeEquals(404);
    $this->assertFalse($this->reload($expired)->isEnabled());
    $this->assertSame(0, (int) $this->reload($expired)->get('click_count')->value);

    // A link that hits its click limit stops redirecting.
    $this->createShortlink('go/once', ['max_clicks' => 1]);
    $this->get('go/once');
    $this->assertSession()->statusCodeEquals(301);
    $this->get('go/once');
    $this->assertSession()->statusCodeEquals(404);
  }

  /**
   * Tests that the shipped admin listing view renders.
   */
  public function testAdminListing(): void {
    $this->createShortlink('go/listed', ['label' => 'Listed shortlink']);

    $this->drupalGet('admin/content/shortlink');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['administer shortlink']));
    $this->drupalGet('admin/content/shortlink');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('go/listed');
  }

  /**
   * Tests that changing the path prefix moves the redirect route.
   */
  public function testPathPrefix(): void {
    $this->createShortlink('go/abc123');
    $this->createShortlink('r/abc123', ['destination_override' => '/user/password']);

    $this->config('shortlink_manager.settings')->set('path_prefix', 'r')->save();
    $this->container->get('router.builder')->rebuild();

    $this->get('r/abc123');
    $this->assertSession()->statusCodeEquals(301);
    $this->assertStringEndsWith('/user/password', $this->getSession()->getResponseHeader('Location'));
    $this->get('go/abc123');
    $this->assertSession()->statusCodeEquals(404);
  }

}
