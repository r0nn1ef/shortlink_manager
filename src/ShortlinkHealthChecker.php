<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Path\PathValidatorInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Service for checking the health of shortlink destinations.
 */
class ShortlinkHealthChecker {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The path validator.
   *
   * @var \Drupal\Core\Path\PathValidatorInterface
   */
  protected PathValidatorInterface $pathValidator;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * Constructs a ShortlinkHealthChecker service.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Path\PathValidatorInterface $path_validator
   *   The path validator.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    PathValidatorInterface $path_validator,
    ClientInterface $http_client,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->pathValidator = $path_validator;
    $this->httpClient = $http_client;
  }

  /**
   * Checks all shortlink destinations for issues.
   *
   * @return array
   *   An array keyed by shortlink ID with values describing the issue:
   *   - 'deleted': Target entity no longer exists.
   *   - 'unpublished': Target entity is unpublished.
   *   - 'invalid_path': Destination override path is invalid.
   */
  public function checkDestinations(): array {
    $issues = [];
    $storage = $this->entityTypeManager->getStorage('shortlink');

    $ids = $storage->getQuery()
      ->condition('status', TRUE)
      ->accessCheck(FALSE)
      ->execute();

    $shortlinks = $storage->loadMultiple($ids);

    foreach ($shortlinks as $shortlink) {
      $id = (int) $shortlink->id();

      // Check entity-referenced destinations.
      $entity_type = $shortlink->getTargetEntityType();
      $entity_id = $shortlink->getTargetEntityId();

      if (!empty($entity_type) && !empty($entity_id)) {
        $target_storage = $this->entityTypeManager->getStorage($entity_type);
        $target = $target_storage->load($entity_id);

        if (!$target) {
          $issues[$id] = [
            'shortlink' => $shortlink,
            'issue' => 'deleted',
            'details' => "Target entity {$entity_type}:{$entity_id} no longer exists.",
          ];
          continue;
        }

        // Check published status if the entity supports it.
        if (method_exists($target, 'isPublished') && !$target->isPublished()) {
          $issues[$id] = [
            'shortlink' => $shortlink,
            'issue' => 'unpublished',
            'details' => "Target entity {$entity_type}:{$entity_id} is unpublished.",
          ];
        }
        continue;
      }

      // Check destination_override paths.
      $override = $shortlink->getDestinationOverride();
      if (!empty($override)) {
        // Internal paths starting with "/".
        if (str_starts_with($override, '/')) {
          $url = $this->pathValidator->getUrlIfValid(ltrim($override, '/'));
          if (!$url) {
            $issues[$id] = [
              'shortlink' => $shortlink,
              'issue' => 'invalid_path',
              'details' => "Destination override path '{$override}' is not a valid route.",
            ];
          }
        }
      }
    }

    return $issues;
  }

  /**
   * Checks for redirect chains in shortlink destinations.
   *
   * @return array
   *   An array keyed by shortlink ID with redirect chain information.
   */
  public function checkRedirectChains(): array {
    $chains = [];
    $storage = $this->entityTypeManager->getStorage('shortlink');

    $ids = $storage->getQuery()
      ->condition('status', TRUE)
      ->accessCheck(FALSE)
      ->execute();

    $shortlinks = $storage->loadMultiple($ids);

    foreach ($shortlinks as $shortlink) {
      $id = (int) $shortlink->id();

      // Resolve the destination URL.
      try {
        $destination_url = $shortlink->resolveDestinationUrl();
        $url_string = $destination_url->setAbsolute()->toString();
      }
      catch (\Exception $e) {
        continue;
      }

      // Only check external URLs and absolute internal URLs.
      if (empty($url_string) || !str_starts_with($url_string, 'http')) {
        continue;
      }

      try {
        $response = $this->httpClient->request('HEAD', $url_string, [
          'allow_redirects' => FALSE,
          'timeout' => 5,
          'http_errors' => FALSE,
        ]);

        $status = $response->getStatusCode();
        if ($status >= 300 && $status < 400) {
          $location = $response->getHeaderLine('Location');
          $chains[$id] = [
            'shortlink' => $shortlink,
            'status_code' => $status,
            'redirects_to' => $location,
          ];
        }
      }
      catch (GuzzleException $e) {
        // Network errors are not redirect chains, skip.
      }
    }

    return $chains;
  }

  /**
   * Updates the has_broken_destination flag on shortlinks.
   *
   * @param array $issues
   *   The issues array from checkDestinations().
   */
  public function flagBrokenDestinations(array $issues): void {
    $storage = $this->entityTypeManager->getStorage('shortlink');

    // Clear all existing flags first.
    $flagged_ids = $storage->getQuery()
      ->condition('has_broken_destination', TRUE)
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($flagged_ids)) {
      $flagged = $storage->loadMultiple($flagged_ids);
      foreach ($flagged as $shortlink) {
        $shortlink->set('has_broken_destination', FALSE);
        $shortlink->save();
      }
    }

    // Set flags for current issues.
    foreach ($issues as $issue) {
      $shortlink = $issue['shortlink'];
      $shortlink->set('has_broken_destination', TRUE);
      $shortlink->save();
    }
  }

}
