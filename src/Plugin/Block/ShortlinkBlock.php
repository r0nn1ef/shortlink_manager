<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Link;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\Path\PathValidatorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a block to display shortlinks for the current page.
 *
 * @Block(
 * id = "shortlink_manager_shortlink_block",
 * admin_label = @Translation("Shortlink Block"),
 * category = @Translation("Shortlink Manager"),
 * )
 */
final class ShortlinkBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $currentUser;

  /**
   * Current route match.
   *
   * @var \Drupal\Core\Routing\CurrentRouteMatch
   */
  protected CurrentRouteMatch $routeMatch;

  /**
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * @var \Drupal\Core\Path\PathValidatorInterface;
   */
  protected $pathValidator;

  /**
   * Constructs a new ShortlinkBlock instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Routing\CurrentRouteMatch $routeMatch
   *   The current route match.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The requestStack for this request.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, AccountInterface $current_user, CurrentRouteMatch $routeMatch, RequestStack $requestStack, PathValidatorInterface $path_validator) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->routeMatch = $routeMatch;
    $this->requestStack = $requestStack;
    $this->pathValidator = $path_validator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('current_route_match'),
      $container->get('request_stack'),
      $container->get('path.validator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    // Check for the required permission. You will need to define this
    // permission in your module's shortlink_manager.permissions.yml file.
    if (!$this->currentUser->hasPermission('view shortlink block')) {
      return [];
    }

    $shortlink_storage = $this->entityTypeManager->getStorage('shortlink');

    $request = $this->requestStack->getCurrentRequest();

    // Get the path without the query string.
    $path = $request->getPathInfo();

    // Get the Shortlink that has a destination_override that matches $path.
    $shortlink_ids = $shortlink_storage->getQuery()
      ->condition('destination_override', $path)
      ->condition('status', TRUE)
      ->accessCheck(FALSE)
      ->execute();

    if (empty($shortlink_ids)) {
      $entity = NULL;

      // Check for a canonical entity route parameter.
      foreach ($this->routeMatch->getParameters()->all() as $param) {
        if ($param instanceof EntityInterface) {
          $entity = $param;
          break;
        }
      }

      if (!is_null($entity)) {
        // Get the entity type ID and entity ID.
        $entity_type_id = $entity->getEntityTypeId();
        $entity_id = $entity->id();

        // Query for shortlink entities that point to this entity.

        $shortlink_ids = $shortlink_storage->getQuery()
          ->condition('target_entity_type', $entity_type_id)
          ->condition('target_entity_id', $entity_id)
          ->condition('status', TRUE)
          ->accessCheck(FALSE)
          ->execute();
      }
    }

    if (empty($shortlink_ids)) {
      // No shortlinks found for this entity.
      return [];
    }

    $shortlinks = $shortlink_storage->loadMultiple($shortlink_ids);

    $items = [];

    /** @var \Drupal\shortlink_manager\Entity\Shortlink $shortlink */
    foreach ($shortlinks as $shortlink) {
      if (!empty($shortlink->getDestinationOverride())) {
        \Drupal::logger('shortlink')->debug('<pre>@data</pre>', ['@data' => $shortlink->getDestinationOverride()]);
        $path = $shortlink->getDestinationOverride();
        /*
         * If the destination override begins with "/", this is considered local
         * to this site so we'll try to validate the path. If it does not validate
         * we will skip adding the link. External URL's we will assume are always
         * valid and show the shortlink regardless.
         */
        if ( strpos($path, '/') === 1) {
          if (!$this->pathValidator->isValid($path)) {
            continue;
          }
          $shortlink_url = Url::fromUri('internal:/' . $shortlink->getPath(), ['absolute' => TRUE]);
        } else {
          $shortlink_url = Url::fromUserInput($shortlink->getPath(), ['absolute' => TRUE]);
        }
      } else {
        $shortlink_url = Url::fromUri('internal:/' . $shortlink->getPath(), ['absolute' => TRUE]);
      }

      $path = $shortlink->getPath();
      $utm_set = $shortlink->getUtmSet();

      if( !is_null($utm_set)) {
        $label = $utm_set->label();
      } else {
        $label = $this->t('General link');
      }

      $items[] = Link::fromTextAndUrl($label . ': ' . $path, $shortlink_url);
    }

    if ( !empty($entity) ) {
      $tags = $entity->getCacheTags();
    } else {
      $tags = [];
    }

    $build = [
      '#theme' => 'item_list',
      '#items' => $items,
      '#cache' => [
        'tags' => $tags,
        'context' => $this->getCacheContexts(),
      ],
    ];

    return $build;
  }

  /**
   * {@inheritDoc}
   */
  public function getCacheContexts(): array {
    return Cache::mergeContexts(parent::getCacheContexts(), ['url.path']);
  }

}
