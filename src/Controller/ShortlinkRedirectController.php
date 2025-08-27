<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller to handle shortlink redirects.
 */
final class ShortlinkRedirectController extends ControllerBase {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new ShortlinkRedirectController object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(ConfigFactoryInterface $configFactory, EntityTypeManagerInterface $entityTypeManager) {
    $this->configFactory = $configFactory;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Redirects a shortlink slug to its destination.
   *
   * @param string $slug
   *   The shortlink slug.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   The redirect response.
   */
  public function redirectShortlink(string $slug): RedirectResponse {
    // Get the default redirect status code from the module configuration.
    $config = $this->configFactory->get('shortlink_manager.settings');
    $shortlinkStorage = $this->entityTypeManager->getStorage('shortlink');

    $path_prefix = $config->get('path_prefix') ?: 'go';

    // Construct the full path string that is stored in the database.
    $full_path = $path_prefix . '/' . $slug;

    // Query for the shortlink entity with the matching full path.
    $shortlink_ids = $shortlinkStorage->getQuery()
      ->condition('path', $full_path)
      ->condition('status', TRUE)
      ->accessCheck(FALSE)
      ->execute();

    if (empty($shortlink_ids)) {
      throw new NotFoundHttpException();
    }

    // Since the path is unique, we should only have one result.
    $shortlink = $shortlinkStorage->load(reset($shortlink_ids));

    if (!$shortlink) {
      throw new NotFoundHttpException();
    }

    $destination_url_string = '';

    // If there is a destination override, use it directly.
    if (!empty($shortlink->getDestinationOverride())) {
      $destination_url_string = $shortlink->getDestinationOverride();
      $destination_url = Url::fromUserInput($destination_url_string, ['absolute' => TRUE]);
    }
    else {
      // Otherwise, load the destination entity.
      $entity_type_id = $shortlink->getTargetEntityType();
      $entity_id = $shortlink->getTargetEntityId();

      $destination_entity = $this->entityTypeManager->getStorage($entity_type_id)->load($entity_id);

      if (!$destination_entity) {
        throw new NotFoundHttpException();
      }

      $destination_url = Url::fromRoute('entity.' . $destination_entity->getEntityTypeId() . '.canonical', [$destination_entity->getEntityType()->id() => $destination_entity->id()]);
    }

    // Check if the shortlink has an associated UTM Set and add the parameters.
    if ($shortlink->hasUtmSet()) {
      /** @var \Drupal\shortlink_manager\UtmSetInterface $utm_set */
      $utm_set = $shortlink->getUtmSet();
      $query_params = [];
      if (!empty($utm_set->getUtmSource())) {
        $query_params['utm_source'] = $utm_set->getUtmSource();
      }
      if (!empty($utm_set->getUtmMedium())) {
        $query_params['utm_medium'] = $utm_set->getUtmMedium();
      }
      if (!empty($utm_set->getUtmCampaign())) {
        $query_params['utm_campaign'] = $utm_set->getUtmCampaign();
      }
      if (!empty($utm_set->getUtmTerm())) {
        $query_params['utm_term'] = $utm_set->getUtmTerm();
      }
      if (!empty($utm_set->getUtmContent())) {
        $query_params['utm_content'] = $utm_set->getUtmContent();
      }
      else {
        $query_params['utm_content'] = $slug;
      }

      // Merge the new query parameters with any existing ones.
      $existing_query = $destination_url->getOption('query') ?? [];
      $destination_url->setOption('query', array_merge($existing_query, $query_params));
    }

    $redirect_status = $config->get('redirect_status') ?: 301;

    return new RedirectResponse($destination_url->toString(), (int) $redirect_status);
  }

}
