<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

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
  protected $configFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a ShortlinkManager service.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager) {
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
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
  public function generateShortlinkPath(int $length = 6): string {
    $config = $this->configFactory->get('shortlink_manager.settings');
    $path_prefix = $config->get('path_prefix') ?? 'go';

    if ($length <= 6) {
      $length = 6;
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
