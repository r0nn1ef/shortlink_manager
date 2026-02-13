<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\path_alias\AliasManagerInterface;

/**
 * Service to manage shortlinks.
 */
class ShortlinkManager {

  use StringTranslationTrait;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected MessengerInterface $messenger;

  /**
   * The path alias manager.
   *
   * @var \Drupal\path_alias\AliasManagerInterface
   */
  protected AliasManagerInterface $aliasManager;

  /**
   * The path validator.
   *
   * @var \Drupal\Core\Path\PathValidatorInterface
   */
  protected PathValidatorInterface $pathValidator;

  /**
   * Constructs a ShortlinkManager service.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\path_alias\AliasManagerInterface $alias_manager
   *   The path alias manager.
   * @param \Drupal\Core\Path\PathValidatorInterface $path_validator
   *   The path validator.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    MessengerInterface $messenger,
    AliasManagerInterface $alias_manager,
    PathValidatorInterface $path_validator,
  ) {
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
    $this->aliasManager = $alias_manager;
    $this->pathValidator = $path_validator;
  }

  /**
   * Generates a unique shortlink path.
   *
   * @param int $maxlength
   *   The maximum lenght of the shortlink path to generate. Default is 28.
   *
   * @return string
   *   The generated shortlink path.
   */
  public function generateShortlinkPath(int $length = 0): string {
    $config = $this->configFactory->get('shortlink_manager.settings');
    $path_prefix = $config->get('path_prefix') ?? 'go';
    $configured_length = (int) ($config->get('path_length') ?? 6);

    if ($length <= 0) {
      $length = $configured_length;
    }

    if ($length < 4) {
      $length = 4;
    }

    /*
     * Define the alphabet of characters to use. Ambiguous characters
     * i, l, I, L, o, O, 0 are removed because they are commonly confused.
     */
    $alphabet = '123456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ-_';
    $alphabet_length = strlen($alphabet);

    // Safety counter to prevent infinite loops
    $max_attempts = 100;
    $attempts = 0;

    // Loop until a unique path is found.
    do {
      $random_string = '';
      for ($i = 0; $i < $length; $i++) {
        $random_string .= $alphabet[random_int(0, $alphabet_length - 1)];
      }
      $path = $path_prefix . '/' . $random_string;

      $attempts++;
      if ($attempts >= $max_attempts) {
        $this->logger->error('Failed to generate unique shortlink path after @attempts attempts', [
          '@attempts' => $max_attempts,
        ]);
        throw new \RuntimeException('Unable to generate unique shortlink path after ' . $max_attempts . ' attempts. Please contact administrator.');
      }
    } while ($this->pathExists($path));

    return $path;
  }

  /**
   * Delete shortlink entities for the specific content entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *
   * @return void
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function deleteEntityShortlinks(EntityInterface $entity): void {
    $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
    $query = $this->entityTypeManager
      ->getStorage('shortlink')
      ->getQuery()
      ->condition('target_entity_type', $entity->getEntityTypeId())
      ->condition('target_entity_id', $entity->id());
    $results = $query
      ->accessCheck(FALSE)
      ->execute();
    // Nothing to do.
    if ( count($results) === 0 ) {
      return;
    }
    $entities = $storage->loadMultiple($results);
    $storage->delete($entities);
    $count_string = $this->formatPlural(count($results), '1 shortlink', '@count shortlinks');
    $this->messenger->addStatus($this->t('Deleted :count_string for %name.',
      [':count_string' => $count_string, '%name' => $entity->label()]
    ));
  }

  /**
   * Validates a custom vanity path slug.
   *
   * @param string $slug
   *   The custom slug (without path prefix).
   * @param int|null $exclude_shortlink_id
   *   An optional shortlink ID to exclude from uniqueness checks (for edits).
   *
   * @return array
   *   An array of error messages. Empty if the slug is valid.
   */
  public function validateCustomPath(string $slug, ?int $exclude_shortlink_id = NULL): array {
    $errors = [];
    $config = $this->configFactory->get('shortlink_manager.settings');
    $path_prefix = $config->get('path_prefix') ?? 'go';

    // Check allowed characters.
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
      $errors[] = (string) $this->t('The custom path may only contain letters, numbers, hyphens, and underscores.');
      return $errors;
    }

    $full_path = $path_prefix . '/' . $slug;

    // Check uniqueness among existing shortlinks.
    $query = $this->entityTypeManager->getStorage('shortlink')
      ->getQuery()
      ->condition('path', $full_path)
      ->accessCheck(FALSE);
    if ($exclude_shortlink_id) {
      $query->condition('id', $exclude_shortlink_id, '<>');
    }
    if (!empty($query->execute())) {
      $errors[] = (string) $this->t('A shortlink with the path %path already exists.', ['%path' => $full_path]);
    }

    // Check for collision with existing path aliases.
    $alias_path = '/' . $full_path;
    $resolved = $this->aliasManager->getPathByAlias($alias_path);
    if ($resolved !== $alias_path) {
      $errors[] = (string) $this->t('The path %path conflicts with an existing path alias.', ['%path' => $full_path]);
    }

    // Check for collision with existing Drupal routes.
    $url = $this->pathValidator->getUrlIfValid($full_path);
    if ($url && $url->getRouteName() !== 'shortlink_manager.redirect') {
      $errors[] = (string) $this->t('The path %path conflicts with an existing route.', ['%path' => $full_path]);
    }

    return $errors;
  }

  /**
   * Gets all shortlinks for a given entity.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param string $entity_id
   *   The entity ID.
   *
   * @return \Drupal\shortlink_manager\ShortlinkInterface[]
   *   An array of shortlink entities.
   */
  public function getShortlinksForEntity(string $entity_type, string $entity_id): array {
    $storage = $this->entityTypeManager->getStorage('shortlink');
    $ids = $storage->getQuery()
      ->condition('target_entity_type', $entity_type)
      ->condition('target_entity_id', $entity_id)
      ->accessCheck(FALSE)
      ->execute();

    if (empty($ids)) {
      return [];
    }

    return $storage->loadMultiple($ids);
  }

  /**
   * Checks if a shortlink path already exists.
   *
   * @param string $path
   *   The path to check.
   *
   * @return bool
   *   TRUE if the path exists, FALSE otherwise.
   */
  protected function pathExists(string $path): bool {
    $storage = $this->entityTypeManager->getStorage('shortlink');

    /*
     * Use a query to check for the existence of a shortlink with the
     * given path.
     */
    $query = $storage->getQuery()
      ->condition('id', $path)
      ->accessCheck(FALSE)
      ->range(0, 1);

    $result = $query->execute();

    return !empty($result);
  }

}