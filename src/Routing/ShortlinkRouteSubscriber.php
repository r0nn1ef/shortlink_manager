<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager\Routing;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Subscriber to alter shortlink routes.
 */
class ShortlinkRouteSubscriber extends RouteSubscriberBase {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new ShortlinkRouteSubscriber object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $configFactory) {
    $this->configFactory = $configFactory;
  }

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    if ($route = $collection->get('shortlink_manager.redirect')) {
      // Load the configurable path prefix from the module's settings.
      $config = $this->configFactory->get('shortlink_manager.settings');
      $path_prefix = $config->get('path_prefix') ?: 'go';

      // Set the path of the route to the configured prefix + {slug}.
      $route->setPath('/' . $path_prefix . '/{slug}');
    }
  }

}
