<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager\Form;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\shortlink_manager\Entity\Shortlink;
use Drupal\shortlink_manager\ShortlinkManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for bulk generating shortlinks for entities missing them.
 */
final class BulkGenerateShortlinksForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The entity type bundle info.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected EntityTypeBundleInfoInterface $bundleInfo;

  /**
   * The shortlink manager service.
   *
   * @var \Drupal\shortlink_manager\ShortlinkManager
   */
  protected ShortlinkManager $shortlinkManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->bundleInfo = $container->get('entity_type.bundle.info');
    $instance->shortlinkManager = $container->get('shortlink_manager.shortlink_manager');
    $instance->configFactory = $container->get('config.factory');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'shortlink_manager_bulk_generate';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->configFactory->get('shortlink_manager.settings');
    $entity_types = $config->get('available_entity_types') ?: [];

    if (empty($entity_types)) {
      $form['message'] = [
        '#markup' => $this->t('No entity types are configured for shortlink generation. Please configure them in the settings.'),
      ];
      return $form;
    }

    $options = [];
    foreach ($entity_types as $entity_type_id) {
      $definition = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
      if (!$definition) {
        continue;
      }
      $bundles = $this->bundleInfo->getBundleInfo($entity_type_id);
      foreach ($bundles as $bundle_id => $bundle_info) {
        $options[$entity_type_id . ':' . $bundle_id] = $definition->getLabel() . ' - ' . $bundle_info['label'];
      }
    }

    $form['bundles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Entity type and bundle'),
      '#options' => $options,
      '#required' => TRUE,
      '#description' => $this->t('Select which entity type/bundle combinations to generate shortlinks for.'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Generate shortlinks'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $selected = array_filter($form_state->getValue('bundles'));

    $batch_builder = new BatchBuilder();
    $batch_builder->setTitle($this->t('Generating shortlinks'))
      ->setFinishCallback([static::class, 'batchFinished']);

    foreach ($selected as $key) {
      [$entity_type_id, $bundle_id] = explode(':', $key, 2);
      $batch_builder->addOperation(
        [static::class, 'batchProcess'],
        [$entity_type_id, $bundle_id]
      );
    }

    batch_set($batch_builder->toArray());
  }

  /**
   * Batch operation callback.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $bundle_id
   *   The bundle ID.
   * @param array $context
   *   The batch context.
   */
  public static function batchProcess(string $entity_type_id, string $bundle_id, array &$context): void {
    $entity_type_manager = \Drupal::entityTypeManager();
    $shortlink_manager = \Drupal::service('shortlink_manager.shortlink_manager');
    $config = \Drupal::config('shortlink_manager.settings');

    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['created'] = 0;

      $query = $entity_type_manager->getStorage($entity_type_id)->getQuery()
        ->accessCheck(FALSE);

      $bundle_key = $entity_type_manager->getDefinition($entity_type_id)->getKey('bundle');
      if ($bundle_key) {
        $query->condition($bundle_key, $bundle_id);
      }

      $context['sandbox']['entity_ids'] = array_values($query->execute());
      $context['sandbox']['total'] = count($context['sandbox']['entity_ids']);
    }

    $limit = 25;
    $chunk = array_slice($context['sandbox']['entity_ids'], $context['sandbox']['progress'], $limit);

    $default_utm_set = $config->get('auto_generate_settings.' . $entity_type_id . '.' . $bundle_id . '.default_utm_set') ?: '';

    foreach ($chunk as $entity_id) {
      // Check if shortlink already exists.
      $existing = $entity_type_manager->getStorage('shortlink')->getQuery()
        ->condition('target_entity_type', $entity_type_id)
        ->condition('target_entity_id', $entity_id)
        ->accessCheck(FALSE)
        ->execute();

      if (empty($existing)) {
        $entity = $entity_type_manager->getStorage($entity_type_id)->load($entity_id);
        if ($entity) {
          $shortlink = new Shortlink([], 'shortlink');
          $shortlink->set('label', t('Auto-generated for @label', ['@label' => $entity->label()]));
          $shortlink->set('path', $shortlink_manager->generateShortlinkPath());
          $shortlink->set('target_entity_type', $entity_type_id);
          $shortlink->set('target_entity_id', $entity_id);
          $shortlink->set('status', TRUE);
          if (!empty($default_utm_set)) {
            $shortlink->set('utm_set', $default_utm_set);
          }

          try {
            $shortlink->save();
            $context['sandbox']['created']++;
          }
          catch (\Exception $e) {
            \Drupal::logger('shortlink_manager')->error('Bulk generate failed for @type:@id: @message', [
              '@type' => $entity_type_id,
              '@id' => $entity_id,
              '@message' => $e->getMessage(),
            ]);
          }
        }
      }

      $context['sandbox']['progress']++;
    }

    $context['results']['created'] = ($context['results']['created'] ?? 0) + $context['sandbox']['created'];
    $context['finished'] = $context['sandbox']['total'] > 0
      ? $context['sandbox']['progress'] / $context['sandbox']['total']
      : 1;
  }

  /**
   * Batch finished callback.
   *
   * @param bool $success
   *   Whether the batch succeeded.
   * @param array $results
   *   The batch results.
   * @param array $operations
   *   The remaining operations.
   */
  public static function batchFinished(bool $success, array $results, array $operations): void {
    if ($success) {
      $created = $results['created'] ?? 0;
      \Drupal::messenger()->addStatus(t('Generated @count shortlinks.', ['@count' => $created]));
    }
    else {
      \Drupal::messenger()->addError(t('An error occurred during bulk shortlink generation.'));
    }
  }

}
