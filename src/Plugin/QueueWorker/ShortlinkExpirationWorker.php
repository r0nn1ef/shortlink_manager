<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes shortlink expiration checks.
 *
 * @QueueWorker(
 *   id = "shortlink_expiration",
 *   title = @Translation("Shortlink expiration processor"),
 *   cron = {"time" = 60}
 * )
 */
class ShortlinkExpirationWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a ShortlinkExpirationWorker object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('logger.factory')->get('shortlink_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (empty($data['shortlink_id'])) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('shortlink');
    $shortlink = $storage->load($data['shortlink_id']);

    if (!$shortlink || !$shortlink->isEnabled()) {
      return;
    }

    if ($shortlink->isExpired()) {
      $shortlink->set('status', FALSE);
      $shortlink->save();
      $this->logger->notice('Shortlink %label (ID: @id) has been disabled due to expiration.', [
        '%label' => $shortlink->label(),
        '@id' => $shortlink->id(),
      ]);
    }
  }

}
