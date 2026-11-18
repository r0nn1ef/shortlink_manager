<?php

declare(strict_types=1);

namespace Drupal\Tests\shortlink_manager\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\KernelTestBase;
use Drupal\shortlink_manager\Controller\ShortlinkRedirectController;
use Drupal\shortlink_manager\Entity\Shortlink;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Base class for Shortlink Manager kernel tests.
 *
 * Installs the shortlink entity, the click tables and the module settings,
 * and offers helpers to create shortlinks and follow them through the
 * redirect controller at a controlled request time.
 */
abstract class ShortlinkKernelTestBase extends KernelTestBase {

  /**
   * A desktop browser user agent, classified as a human click.
   */
  protected const BROWSER = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

  /**
   * A fixed request time (2025-11-17 17:20:00 UTC).
   */
  protected const NOW = 1_763_400_000;

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
    'entity_test',
    'shortlink_manager',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // Freeze the clock so tests control "now" on every Drupal 11 minor.
    $container->register('datetime.time', FrozenTime::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('shortlink');
    $this->installSchema('shortlink_manager', ['shortlink_clicks', 'shortlink_clicks_daily']);
    $this->installConfig(['system']);
    // Install only the module settings. The shipped admin view is not needed
    // here and is covered by the functional test, which installs the module
    // the normal way.
    $settings = Yaml::decode(file_get_contents(dirname(__DIR__, 3) . '/config/install/shortlink_manager.settings.yml'));
    $this->config('shortlink_manager.settings')->setData($settings)->save();
    $this->config('system.site')->set('name', 'testsite')->save();
    $this->setRequestTime(static::NOW);
  }

  /**
   * Creates and saves a shortlink.
   *
   * @param array $values
   *   Field values. Defaults to an enabled link at go/abc123 pointing at an
   *   external URL.
   *
   * @return \Drupal\shortlink_manager\Entity\Shortlink
   *   The saved shortlink.
   */
  protected function createShortlink(array $values = []): Shortlink {
    $shortlink = Shortlink::create($values + [
      'label' => 'Test',
      'path' => 'go/abc123',
      'destination_override' => 'https://example.com/post',
      'status' => TRUE,
    ]);
    $shortlink->save();
    return $shortlink;
  }

  /**
   * Reloads a shortlink, bypassing the static cache.
   */
  protected function reload(Shortlink $shortlink): ?Shortlink {
    $storage = $this->container->get('entity_type.manager')->getStorage('shortlink');
    $storage->resetCache([$shortlink->id()]);
    return $storage->load($shortlink->id());
  }

  /**
   * Builds a request at a given time and makes it the current request.
   *
   * This also freezes the time service (see FrozenTime), which sets "now"
   * for Shortlink::isExpired(), the click tracker and the queue worker.
   */
  protected function setRequestTime(int $time, string $user_agent = self::BROWSER, string $ip = '203.0.113.5'): Request {
    FrozenTime::$time = $time;
    $request = Request::create('/go/abc123', 'GET', [], [], [], [
      'REQUEST_TIME' => $time,
      'REQUEST_TIME_FLOAT' => (float) $time,
      'REMOTE_ADDR' => $ip,
      'HTTP_USER_AGENT' => $user_agent,
    ]);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
    return $request;
  }

  /**
   * Follows a shortlink slug through the redirect controller.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The redirect response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   When the link is unknown, disabled, expired or its target is gone.
   */
  protected function follow(string $slug = 'abc123', ?int $time = NULL, string $user_agent = self::BROWSER): Response {
    $request = $this->setRequestTime($time ?? static::NOW, $user_agent);
    $controller = ShortlinkRedirectController::create($this->container);
    try {
      return $controller->redirectShortlink($slug, $request);
    }
    finally {
      $this->container->get('request_stack')->pop();
    }
  }

}
