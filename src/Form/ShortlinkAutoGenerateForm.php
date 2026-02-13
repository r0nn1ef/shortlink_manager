<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Url;
use Drupal\Component\Utility\Html;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Shortlink manager settings for this site.
 */
final class ShortlinkAutoGenerateForm extends ConfigFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity type bundle info.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $entityTypeBundleInfo;

  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, EntityTypeBundleInfoInterface $entity_type_bundle_info) {
    parent::__construct($config_factory);
    $this->entityTypeManager = $entity_type_manager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'shortlink_manager_shortlink_auto_generate';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['shortlink_manager.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // Get the selected entity types from the general settings form.
    $config = $this->config('shortlink_manager.settings');
    $entity_types_to_configure = $config->get('available_entity_types') ?: [];

    // If no entity types are selected, show a message.
    if (empty($entity_types_to_configure)) {
      $form['empty_message'] = [
        '#type' => 'markup',
        '#markup' => $this->t('Please select which entity types to configure on the <a href=":url">General settings</a> page.', [':url' => Url::fromRoute('shortlink_manager.settings')->toString()]),
        '#prefix' => '<p>',
        '#suffix' => '</p>',
      ];
      return parent::buildForm($form, $form_state);
    }

    // Load all available UTM Sets to populate the dropdown.
    $utm_sets = $this->entityTypeManager->getStorage('utm_set')->loadMultiple();
    $utm_options = ['' => $this->t('- None -')];
    foreach ($utm_sets as $utm_set) {
      $utm_options[$utm_set->id()] = $utm_set->label();
    }

    // Use a vertical tabs container to organize the settings.
    $form['settings_tabs'] = [
      '#type' => 'vertical_tabs',
    ];

    foreach ($entity_types_to_configure as $entity_type_id) {
      $entity_type_definition = $this->entityTypeManager->getDefinition($entity_type_id);
      $bundles = $this->entityTypeBundleInfo->getBundleInfo($entity_type_id);

      // Create a tab for each selected entity type.
      $form['settings_tabs'][$entity_type_id . '_tab'] = [
        '#type' => 'details',
        '#title' => $entity_type_definition->getLabel(),
        '#group' => 'settings_tabs',
      ];

      foreach ($bundles as $bundle_id => $bundle_info) {
        $bundle_label = $bundle_info['label'];

        // Get the nested values from the new schema structure.
        $enabled = $config->get('auto_generate_settings.' . $entity_type_id . '.' . $bundle_id . '.enabled');
        $utm_set = $config->get('auto_generate_settings.' . $entity_type_id . '.' . $bundle_id . '.utm_set');

        // Add a fieldset to group the settings for each bundle.
        // The key for this fieldset is the bundle ID itself.
        $form['settings_tabs'][$entity_type_id . '_tab'][$bundle_id] = [
          '#type' => 'details',
          '#title' => $this->t('@bundle_label settings', ['@bundle_label' => $bundle_label]),
          '#open' => (bool) $enabled,
        ];

        // Use #parents to force the correct naming structure.
        $parents = ['settings_tabs', $entity_type_id . '_tab', $bundle_id];

        $form['settings_tabs'][$entity_type_id . '_tab'][$bundle_id]['enabled'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Enable shortlink generation'),
          '#default_value' => $enabled,
          '#parents' => array_merge($parents, ['enabled']),
        ];

        $form['settings_tabs'][$entity_type_id . '_tab'][$bundle_id]['utm_set'] = [
          '#type' => 'select',
          '#title' => $this->t('Predefined UTM Set'),
          '#options' => $utm_options,
          '#description' => $this->t('Select one or more UTM Sets to automatically append to all generated shortlinks for this bundle.'),
          '#default_value' => $utm_set,
          '#multiple' => TRUE,
          '#size' => 5,
          '#parents' => array_merge($parents, ['utm_set']),
          '#states' => [
            'visible' => [
              ':input[name="' . Html::escape(implode('][', array_merge($parents, ['enabled']))) . '"]' => ['checked' => TRUE],
            ],
          ],
        ];

        $default_utm_set = $config->get('auto_generate_settings.' . $entity_type_id . '.' . $bundle_id . '.default_utm_set');
        $form['settings_tabs'][$entity_type_id . '_tab'][$bundle_id]['default_utm_set'] = [
          '#type' => 'select',
          '#title' => $this->t('Default UTM Set'),
          '#options' => $utm_options,
          '#description' => $this->t('The default UTM Set to pre-fill when manually creating shortlinks for this bundle.'),
          '#default_value' => $default_utm_set ?? '',
          '#parents' => array_merge($parents, ['default_utm_set']),
          '#states' => [
            'visible' => [
              ':input[name="' . Html::escape(implode('][', array_merge($parents, ['enabled']))) . '"]' => ['checked' => TRUE],
            ],
          ],
        ];
      }
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('shortlink_manager.settings');

    // Get the submitted values for the entire form.
    $submitted_values = $form_state->getValues();

    // The per-bundle settings are nested inside the 'settings_tabs' key.
    if (isset($submitted_values['settings_tabs'])) {
      $settings_tabs_values = $submitted_values['settings_tabs'];

      // Iterate through the entity type tabs (e.g., 'node_tab', 'media_tab').
      foreach ($settings_tabs_values as $entity_type_tab_key => $entity_type_values) {
        // We only care about the elements that end with '_tab'.
        if (str_ends_with($entity_type_tab_key, '_tab')) {
          $entity_type_id = str_replace('_tab', '', $entity_type_tab_key);

          // Iterate through the bundle settings within each entity type tab.
          foreach ($entity_type_values as $bundle_id => $bundle_values) {
            // Check if the submitted values exist for this bundle.
            if (is_array($bundle_values)) {
              $enabled_value = $bundle_values['enabled'] ?? FALSE;

              // Check if auto-generation is enabled.
              if ($enabled_value) {
                // If enabled, save the selected UTM sets.
                $utm_set_value = $bundle_values['utm_set'] ?? [];
              }
              else {
                // If disabled, clear the UTM sets.
                $utm_set_value = [];
              }

              $default_utm_set_value = $bundle_values['default_utm_set'] ?? '';

              // Save the values to the nested configuration keys.
              $config->set('auto_generate_settings.' . $entity_type_id . '.' . $bundle_id . '.enabled', $enabled_value);
              $config->set('auto_generate_settings.' . $entity_type_id . '.' . $bundle_id . '.utm_set', $utm_set_value);
              $config->set('auto_generate_settings.' . $entity_type_id . '.' . $bundle_id . '.default_utm_set', $default_utm_set_value);
            }
          }
        }
      }
    }

    $config->save();
    parent::submitForm($form, $form_state);
  }

}
