<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager\Plugin\Block;

use Drupal\Core\Access\AccessResult;
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
use Symfony\Component\HttpFoundation\Request;

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
    $request = $this->requestStack->getCurrentRequest();
    $results = $this->findShortlinks($request);
    $shortlinks = $results['shortlinks'];
    $entity = $results['entity'];
    $path = $request->getPathInfo();

    // The access check in blockAccess() should prevent us from getting here
    // if there are no shortlinks or the user doesn't have permission.
    if (empty($shortlinks)) {
      return [];
    }

    $build = [
      '#theme' => 'shortlink_manager_block_content',
      '#shortlinks' => $shortlinks,
      '#entity' => $entity,
      '#current_path' => $path,
      // Setup cacheability.
      '#cache' => [
        // Ensure cache tags from the entity are included.
        'tags' => $entity ? $entity->getCacheTags() : [],
        'contexts' => $this->getCacheContexts(),
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

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    // 1. Check the required permission first.
    if (!$account->hasPermission('view shortlink block')) {
      return AccessResult::forbidden()->addCacheContexts(['user.permissions']);
    }

    // 2. Check if there are any shortlinks for the current page.
    $request = $this->requestStack->getCurrentRequest();
    $results = $this->findShortlinks($request);

    if (!empty($results['shortlinks'])) {
      $access_result = AccessResult::allowed();
      // Add cache tags if an entity was found, ensuring the block is
      // invalidated if the entity changes.
      if ($results['entity'] instanceof EntityInterface) {
        $access_result->addCacheableDependency($results['entity']);
      }
      // Add the 'url.path' context, which is also in getCacheContexts().
      $access_result->addCacheContexts(['url.path']);
      return $access_result;
    }

    // 3. Deny access if no shortlinks are found.
    // The cache contexts and dependencies from findShortlinks are automatically
    // inherited if we return AccessResult::forbidden(), but explicitly adding
    // 'url.path' ensures correct caching.
    return AccessResult::forbidden()->addCacheContexts(['url.path']);
  }

  /**
   * Finds shortlink entities for the current request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   * The current request.
   *
   * @return array
   * An array containing the shortlinks and the target entity (if found).
   */
  private function findShortlinks(Request $request): array {
    $shortlink_storage = $this->entityTypeManager->getStorage('shortlink');
    $path = $request->getPathInfo();
    $entity = NULL;
    $shortlinks = [];

    // 1. Check for shortlinks with a destination override matching the path.
    $shortlink_ids = $shortlink_storage->getQuery()
      ->condition('destination_override', $path)
      ->condition('status', TRUE)
      ->accessCheck(FALSE)
      ->execute();

    if (empty($shortlink_ids)) {
      // 2. If none found, check for a target entity in the route parameters.
      foreach ($this->routeMatch->getParameters()->all() as $param) {
        if ($param instanceof EntityInterface) {
          $entity = $param;
          break;
        }
      }

      if (!is_null($entity)) {
        $entity_type_id = $entity->getEntityTypeId();
        $entity_id = $entity->id();

        $shortlink_ids = $shortlink_storage->getQuery()
          ->condition('target_entity_type', $entity_type_id)
          ->condition('target_entity_id', $entity_id)
          ->condition('status', TRUE)
          ->accessCheck(FALSE)
          ->execute();
      }
    }

    if (!empty($shortlink_ids)) {
      $shortlinks = $shortlink_storage->loadMultiple($shortlink_ids);
    }

    return [
      'shortlinks' => $shortlinks,
      'entity' => $entity,
    ];
  }

}
