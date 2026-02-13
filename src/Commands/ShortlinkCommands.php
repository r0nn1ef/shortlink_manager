<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drush\Commands\DrushCommands;
use Drupal\shortlink_manager\ShortlinkHealthChecker;
use Drupal\shortlink_manager\ShortlinkManager;

/**
 * Drush commands for the Shortlink Manager module.
 */
final class ShortlinkCommands extends DrushCommands {

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
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The shortlink manager service.
   *
   * @var \Drupal\shortlink_manager\ShortlinkManager
   */
  protected $shortlinkManager;

  /**
   * The health checker service.
   *
   * @var \Drupal\shortlink_manager\ShortlinkHealthChecker
   */
  protected ShortlinkHealthChecker $healthChecker;

  /**
   * Constructs a new ShortlinkCommands object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\shortlink_manager\ShortlinkManager $shortlinkManager
   *   The shortlink manager service.
   * @param \Drupal\shortlink_manager\ShortlinkHealthChecker $healthChecker
   *   The health checker service.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    EntityTypeManagerInterface $entityTypeManager,
    MessengerInterface $messenger,
    ShortlinkManager $shortlinkManager,
    ShortlinkHealthChecker $healthChecker,
  ) {
    parent::__construct();
    $this->configFactory = $configFactory;
    $this->entityTypeManager = $entityTypeManager;
    $this->messenger = $messenger;
    $this->shortlinkManager = $shortlinkManager;
    $this->healthChecker = $healthChecker;
  }

  /**
   * Creates shortlinks for all content with missing shortlinks.
   *
   * @command shortlink:add-missing-links
   * @aliases sl:add-missing
   * @usage shortlink:add-missing-links
   *   Creates shortlinks for all content that should have one but doesn't.
   */
  public function addMissingLinks(): void {
    $this->io()->note('Starting process to create missing shortlinks...');
    $created_count = 0;
    $skipped_count = 0;

    // Load the shortlink_manager settings.
    $config = $this->configFactory->get('shortlink_manager.settings');
    $auto_generate_settings = $config->get('auto_generate_settings');

    if (empty($auto_generate_settings)) {
      $this->io()->warning('No content types are configured for shortlink creation.');
      return;
    }

    foreach ($auto_generate_settings as $entity_type_id => $bundles) {
      if (!is_array($bundles)) {
        continue;
      }
      foreach ($bundles as $bundle_id => $bundle_settings) {
        if (!empty($bundle_settings['enabled'])) {
          // Get all published entities of the enabled bundle.
          $storage = $this->entityTypeManager->getStorage($entity_type_id);
          $query = $storage->getQuery()
            ->accessCheck(FALSE)
            ->condition('status', 1)
            ->condition($storage->getEntityType()->getKey('bundle'), $bundle_id);

          $entity_ids = $query->execute();
          // Get the UTM set IDs from the configuration for this bundle.
          $utm_set_ids = $config->get('auto_generate_settings.' . $entity_type_id . '.' . $bundle_id . '.utm_set');

          foreach ($entity_ids as $entity_id) {
            /*
             * Check if shortlinks already exist for this entity for all
             * configured UTM sets.
             */
            $existing_shortlinks = $this->entityTypeManager->getStorage('shortlink')->loadByProperties([
              'target_entity_type' => $entity_type_id,
              'target_entity_id' => $entity_id,
            ]);

            $existing_utm_set_ids = [];
            foreach ($existing_shortlinks as $shortlink) {
              if ($shortlink->hasField('utm_set') && $utm_set = $shortlink->get('utm_set')->entity) {
                $existing_utm_set_ids[] = $utm_set->id();
              }
            }

            // Determine which UTM sets still need a shortlink created.
            $utm_sets_to_create = array_diff($utm_set_ids, $existing_utm_set_ids);

            foreach ($utm_sets_to_create as $utm_set_id) {
              $result = $this->createShortlinkForEntity($entity_type_id, $entity_id, $utm_set_id);
              if ($result) {
                $created_count++;
              }
              else {
                $skipped_count++;
              }
            }
          }
        }
      }
    }

    if ($created_count == 0 && $skipped_count == 0) {
      $this->io()->success('No content found that needs a shortlink.');
      return;
    }

    $this->io()->success(sprintf('Finished successfully. Created %d shortlinks and skipped %d items.', $created_count, $skipped_count));
  }

  /**
   * Checks shortlink destinations for broken links.
   *
   * @command shortlink:check-destinations
   * @aliases sl:check
   * @usage shortlink:check-destinations
   *   Checks all active shortlinks for broken or invalid destinations.
   */
  public function checkDestinations(): void {
    $this->io()->note('Checking shortlink destinations...');

    $issues = $this->healthChecker->checkDestinations();

    if (empty($issues)) {
      $this->io()->success('All shortlink destinations are healthy.');
      return;
    }

    $rows = [];
    foreach ($issues as $id => $issue) {
      $shortlink = $issue['shortlink'];
      $rows[] = [
        $id,
        $shortlink->label(),
        $shortlink->getPath(),
        $issue['issue'],
        $issue['details'],
      ];
    }

    $this->io()->table(
      ['ID', 'Label', 'Path', 'Issue', 'Details'],
      $rows
    );

    $this->io()->warning(sprintf('Found %d shortlinks with broken destinations.', count($issues)));

    if ($this->io()->confirm('Flag these shortlinks as having broken destinations?')) {
      $this->healthChecker->flagBrokenDestinations($issues);
      $this->io()->success('Broken destination flags updated.');
    }
  }

  /**
   * Checks for redirect chains in shortlink destinations.
   *
   * @command shortlink:check-chains
   * @aliases sl:chains
   * @usage shortlink:check-chains
   *   Checks all active shortlinks for redirect chain issues.
   */
  public function checkRedirectChains(): void {
    $this->io()->note('Checking for redirect chains...');

    $chains = $this->healthChecker->checkRedirectChains();

    if (empty($chains)) {
      $this->io()->success('No redirect chains detected.');
      return;
    }

    $rows = [];
    foreach ($chains as $id => $chain) {
      $shortlink = $chain['shortlink'];
      $rows[] = [
        $id,
        $shortlink->label(),
        $shortlink->getPath(),
        $chain['status_code'],
        $chain['redirects_to'],
      ];
    }

    $this->io()->table(
      ['ID', 'Label', 'Path', 'Status', 'Redirects to'],
      $rows
    );

    $this->io()->warning(sprintf('Found %d shortlinks with redirect chains.', count($chains)));
  }

  /**
   * Helper method to create a shortlink for a single entity.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $entity_id
   *   The entity ID.
   * @param string $utm_set_id
   *   The UTM Set ID to create a shortlink for.
   *
   * @return bool
   *   TRUE if a shortlink was created, FALSE otherwise.
   */
  private function createShortlinkForEntity(string $entity_type_id, string $entity_id, string $utm_set_id): bool {
    try {
      // Load the entity.
      $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($entity_id);
      if (!$entity) {
        $this->io()->warning(dt('Skipped entity %type:%id because it could not be loaded.',
          ['%type' => $entity_type_id, '%id' => $entity_id]));
        return FALSE;
      }

      // Load the UTM Set entity.
      $utm_set = $this->entityTypeManager->getStorage('utm_set')->load($utm_set_id);
      if (!$utm_set) {
        $this->io()->warning(dt('Skipped entity %type:%id because the UTM Set with ID %utm_set_id could not be loaded.',
          ['%type' => $entity_type_id, '%id' => $entity_id, '%utm_set_id' => $utm_set_id]));
        return FALSE;
      }

      // Create the Shortlink entity using the entity storage service.
      $shortlink = $this->entityTypeManager->getStorage('shortlink')->create([
        'label' => dt('Auto-generated for @label (@utm_set_label)', [
          '@label' => $entity->label(),
          '@utm_set_label' => $utm_set->label(),
        ]),
        'path' => $this->shortlinkManager->generateShortlinkPath(),
        'target_entity_type' => $entity->getEntityTypeId(),
        'target_entity_id' => $entity->id(),
        'description' => dt('Auto-generated for @bundle from Drush command with UTM set: @utm_set_label', [
          '@bundle' => $entity->getEntityType()->getBundleLabel($entity->bundle()),
          '@utm_set_label' => $utm_set->label(),
        ]),
        'status' => TRUE,
        'utm_set' => $utm_set->id(),
      ]);

      $shortlink->save();
      $this->io()->text(dt('Created shortlink with ID @id for entity @entity_id with UTM set @utm_set_id.', [
        '@id' => $shortlink->id(),
        '@entity_id' => $entity_id,
        '@utm_set_id' => $utm_set_id,
      ]));
      return TRUE;
    }
    catch (\Exception $e) {
      $this->io()->error(dt('Failed to create shortlink for entity %label (%type:%id) with UTM set %utm_set_id. Error: %error', [
        '%label' => $entity ? $entity->label() : 'N/A',
        '%type' => $entity ? $entity->getEntityTypeId() : $entity_type_id,
        '%id' => $entity ? $entity->id() : $entity_id,
        '%utm_set_id' => $utm_set_id,
        '%error' => $e->getMessage(),
      ]));
      return FALSE;
    }
  }

}
