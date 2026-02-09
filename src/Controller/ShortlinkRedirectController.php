<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Utility\Token;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
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
   * The token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * Constructs a new ShortlinkRedirectController object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Utility\Token $token
   *   The token service.
   */
  public function __construct(ConfigFactoryInterface $configFactory, EntityTypeManagerInterface $entityTypeManager, Token $token) {
    $this->configFactory = $configFactory;
    $this->entityTypeManager = $entityTypeManager;
    $this->token = $token;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('token')
    );
  }

  /**
   * Redirects a shortlink slug to its destination.
   *
   * @param string $slug
   *   The shortlink slug.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The redirect response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *
   */
  public function redirectShortlink(string $slug): Response {
    // Get the default redirect status code from the module configuration.
    $config = $this->configFactory->get('shortlink_manager.settings');
    $shortlinkStorage = $this->entityTypeManager->getStorage('shortlink');

    $destination_entity = NULL;

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

    $current_count = (int) $shortlink->get('click_count')->value;
    $shortlink->set('click_count', $current_count + 1);
    $shortlink->set('last_accessed', \Drupal::time()->getRequestTime());
    $shortlink->save();

    // If there is a destination override, use it directly.
    if (!empty($shortlink->getDestinationOverride())) {
      $destination_url_string = $shortlink->getDestinationOverride();

      // NEW: Set up data context for token replacement (using the slug as context).
      // We pass $slug here as the only known context if no destination entity exists.
      $data = ['shortlink' => (object) ['slug' => $slug]];

      // NEW: Process token replacement on the destination URL string.
      // We use the injected token service.
      $processed_destination = $this->token->replace($destination_url_string, $data);

      $destination_url_string = $processed_destination;
      /*
       * If the destination override begins with "/", it is considered internal
       * to this site. Otherwise, it is considered an external URL.
       */
      if ( strpos($destination_url_string, '/') === 0 ) {
        $destination_url = Url::fromUserInput($destination_url_string, ['absolute' => TRUE]);
      } else {
        $destination_url = Url::fromUri($destination_url_string, ['absolute' => TRUE]);
      }
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

    // Set up the data context for token replacement.
    $data = [];
    if ($destination_entity) {
      $data[$destination_entity->getEntityTypeId()] = $destination_entity;
    }

    // Check if the shortlink has an associated UTM Set and add the parameters.
    if ($shortlink->hasUtmSet()) {
      /** @var \Drupal\shortlink_manager\UtmSetInterface $utm_set */
      $utm_set = $shortlink->getUtmSet();
      $query_params = is_null($utm_set) ? [] : $utm_set->getUtmParameters();

      // Define a helper function (closure) to process tokens.
      $process_token = function (string $raw_value, array $data, array $options = []) {
        // Only run replacement if the value looks like it contains a token.
        if (str_contains($raw_value, '[')) {
          // Use the injected token service.
          return $this->token->replace($raw_value, $data, $options);
        }
        return $raw_value;
      };

      /*
       * Used to clean up the token replacements if needed.
       */
      $pattern = '/[^a-z0-9_]+/i';
      $replacement = '_';
      $double_underscore_pattern = '/_+/';
      foreach ($query_params as $key => $value) {
        if (empty($value)) {
          continue;
        }
        $new_value = $process_token($value, $data);
        $sanitized_value = preg_replace($pattern, $replacement, $new_value);
        $sanitized_value = preg_replace($double_underscore_pattern, '_', $sanitized_value);
        $sanitized_value = trim($sanitized_value, '_');
        $query_params[$key] = strtolower($sanitized_value);
      }

      // Merge the new query parameters with any existing ones.
      $existing_query = $destination_url->getOption('query') ?? [];
      $destination_url->setOption('query', array_merge($existing_query, $query_params));
    }

    $redirect_status = $config->get('redirect_status') ?: 301;

    if ( $destination_url->isExternal() ) {
      $response = new TrustedRedirectResponse($destination_url->toString(), (int) $redirect_status);
    } else {
      $response = new RedirectResponse($destination_url->toString(), (int) $redirect_status);
    }

    // This is the "Invisibility Cloak" for Googlebot
    $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

    return $response;
  }

}
