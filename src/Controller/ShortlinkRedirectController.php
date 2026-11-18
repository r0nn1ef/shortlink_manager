<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Utility\Token;
use Drupal\shortlink_manager\ShortlinkClickTracker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
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
   * The click tracker service.
   *
   * @var \Drupal\shortlink_manager\ShortlinkClickTracker
   */
  protected ShortlinkClickTracker $clickTracker;

  /**
   * Constructs a new ShortlinkRedirectController object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Utility\Token $token
   *   The token service.
   * @param \Drupal\shortlink_manager\ShortlinkClickTracker $clickTracker
   *   The click tracker service.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    EntityTypeManagerInterface $entityTypeManager,
    Token $token,
    ShortlinkClickTracker $clickTracker,
  ) {
    $this->configFactory = $configFactory;
    $this->entityTypeManager = $entityTypeManager;
    $this->token = $token;
    $this->clickTracker = $clickTracker;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('token'),
      $container->get('shortlink_manager.click_tracker')
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
  public function redirectShortlink(string $slug, Request $request): Response {
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

    // Check if the shortlink has expired.
    if ($shortlink->isExpired()) {
      $shortlink->set('status', FALSE);
      $shortlink->save();
      throw new NotFoundHttpException();
    }

    // Token data for the destination override and the UTM values. The
    // shortlink itself is always available, so [shortlink:*] tokens (e.g.
    // utm_id=[shortlink:id] for GA4) resolve on every redirect.
    $data = ['shortlink' => $shortlink];

    // Resolve the destination and build the full redirect URL before any
    // click is recorded, so a request that ends in a 404 (e.g. the target
    // entity was deleted) leaves the counters and the click log untouched.
    // If there is a destination override, use it directly.
    if (!empty($shortlink->getDestinationOverride())) {
      $destination_url_string = $this->token->replace($shortlink->getDestinationOverride(), $data);

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

      if (empty($entity_type_id) || empty($entity_id) || !$this->entityTypeManager->hasDefinition($entity_type_id)) {
        throw new NotFoundHttpException();
      }

      $destination_entity = $this->entityTypeManager->getStorage($entity_type_id)->load($entity_id);

      if (!$destination_entity) {
        throw new NotFoundHttpException();
      }

      $destination_url = Url::fromRoute('entity.' . $destination_entity->getEntityTypeId() . '.canonical', [$destination_entity->getEntityType()->id() => $destination_entity->id()]);
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

    // Generate the URL string now so a failure here can't count as a click.
    $destination_string = $destination_url->toString();

    // The destination resolved, so this request is a real redirect: record
    // the click event in the tracking log and classify it.
    $click = $this->clickTracker->recordClick((int) $shortlink->id(), $request);

    // click_count stays the raw count of every request (bots included) for
    // backward compatibility. Human and unique counts exclude bots, and only
    // human clicks update last_accessed so crawlers don't keep links "active".
    $shortlink->set('click_count', (int) $shortlink->get('click_count')->value + 1);
    if (!$click['is_bot']) {
      $shortlink->set('human_click_count', (int) $shortlink->get('human_click_count')->value + 1);
      $shortlink->set('last_accessed', \Drupal::time()->getRequestTime());
    }
    if ($click['is_unique']) {
      $shortlink->set('unique_click_count', (int) $shortlink->get('unique_click_count')->value + 1);
    }
    $shortlink->save();

    $redirect_status = $config->get('redirect_status') ?: 301;

    if ( $destination_url->isExternal() ) {
      $response = new TrustedRedirectResponse($destination_string, (int) $redirect_status);
    } else {
      $response = new RedirectResponse($destination_string, (int) $redirect_status);
    }

    // New: Allow other modules to alter the response object entirely.
    // This passes the destination, the shortlink entity, and the original response.
    $context = [
      'shortlink' => $shortlink,
      'destination_url' => $destination_url,
    ];

    // Invoke a hook: hook_shortlink_redirect_response_alter
    $this->moduleHandler()->alter('shortlink_redirect_response', $response, $context);

    // This is the "Invisibility Cloak" for Googlebot
    $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

    return $response;
  }

}
