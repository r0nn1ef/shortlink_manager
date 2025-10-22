<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager\Form;

use Drupal\Core\Entity\BundleEntityFormBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\shortlink_manager\Entity\UtmParameterDefinitionType;
use Drupal\shortlink_manager\Plugin\UtmParameterProcessorManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form handler for utm parameter definition type forms.
 */
final class UtmParameterDefinitionTypeForm extends BundleEntityFormBase {

  /**
   * The UTM parameter processor plugin manager.
   *
   * @var \Drupal\shortlink_manager\Plugin\UtmParameterProcessorManager
   */
  protected UtmParameterProcessorManager $processorManager;

  /**
   * Constructs a UtmParameterDefinitionTypeForm object.
   *
   * @param \Drupal\shortlink_manager\Plugin\UtmParameterProcessorManager $processor_manager
   * The UTM parameter processor plugin manager.
   */
  public function __construct(UtmParameterProcessorManager $processor_manager) {
    $this->processorManager = $processor_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    // Inject the custom plugin manager service.
    return new static(
      $container->get('plugin.manager.utm_parameter_processor')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    // The BundleEntityFormBase uses $this->entity for the type object.
    $form = parent::form($form, $form_state);

    if ($this->operation === 'edit') {
      $form['#title'] = $this->t('Edit %label utm parameter definition type', ['%label' => $this->entity->label()]);
    }

    $form['label'] = [
      '#title' => $this->t('Label'),
      '#type' => 'textfield',
      '#default_value' => $this->entity->label(),
      '#description' => $this->t('The human-readable name of this utm parameter definition type.'),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $this->entity->id(),
      '#maxlength' => EntityTypeInterface::BUNDLE_MAX_LENGTH,
      '#machine_name' => [
        'exists' => [UtmParameterDefinitionType::class, 'load'],
        'source' => ['label'],
      ],
      '#description' => $this->t('A unique machine-readable name for this utm parameter definition type. It must only contain lowercase letters, numbers, and underscores.'),
      '#disabled' => !$this->entity->isNew(),
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $this->entity->get('description'),
      '#description' => $this->t('A brief description of what kind of parameter logic this type is designed for (e.g., Static Value, or Dynamic User ID).'),
    ];

    // --- Processor Selection ---

    $plugins = $this->processorManager->getDefinitions();
    $options = [];
    foreach ($plugins as $plugin_id => $plugin) {
      // Use the plugin's label as the option text, and append the description.
      $options[$plugin_id] = $plugin['label'] . ' (' . $plugin['description'] . ')';
    }

    $form['processor_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Parameter Processor'),
      '#description' => $this->t('Select the underlying logic that will generate the final query string value for this parameter type.'),
      '#options' => $options,
      '#default_value' => $this->entity->get('processor_id') ?: 'static_value',
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    /** @var \Drupal\shortlink_manager\Entity\UtmParameterDefinitionType $entity_type */
    $entity_type = $this->entity;

    // Map form values to the configuration entity properties.
    $entity_type->set('processor_id', $form_state->getValue('processor_id'));
    $entity_type->set('description', $form_state->getValue('description'));

    // Save the entity and get the result status (SAVED_NEW or SAVED_UPDATED).
    $result = parent::save($form, $form_state);

    $message_args = ['%label' => $entity_type->label()];

    $this->messenger()->addStatus(
      match($result) {
        SAVED_NEW => $this->t('The utm parameter definition type %label has been added. You can now add fields to this type.', $message_args),
        SAVED_UPDATED => $this->t('The utm parameter definition type %label has been updated.', $message_args),
      }
    );

    // Redirect logic:
    if ($result === SAVED_NEW) {
      // CRITICAL: Redirect the user directly to the Field UI management screen
      // for the new bundle, so they can add the fields required by the processor.
      $form_state->setRedirect(
        'entity.utm_parameter_definition.field_ui_fields',
        [
          'utm_parameter_definition_type' => $entity_type->id(),
        ]
      );
    } else {
      // If updated, redirect back to the collection page.
      $form_state->setRedirectUrl($entity_type->toUrl('collection'));
    }

    return $result;
  }
}
