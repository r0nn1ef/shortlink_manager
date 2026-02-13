<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\shortlink_manager\ShortlinkManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Configure Shortlink Manager settings for this site.
 */
final class ShortlinkSettingsForm extends ConfigFormBase {

  /**
   * The shortlink manager service.
   *
   * @var \Drupal\shortlink_manager\ShortlinkManager
   */
  protected ShortlinkManager $shortlinkManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a new ShortlinkSettingsForm object.
   *
   * @param \Drupal\shortlink_manager\ShortlinkManager $shortlinkManager
   *   The shortlink manager service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(ShortlinkManager $shortlinkManager, EntityTypeManagerInterface $entityTypeManager) {
    $this->shortlinkManager = $shortlinkManager;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('shortlink_manager.shortlink_manager'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'shortlink_manager_shortlink_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['description'] = [
      '#type' => 'item',
      '#markup' => '<p>' . $this->t('This page allows you to manage how shortlinks are created and redirected on
      your site. Use these settings to customize the shortlink path and control how redirects behave.') . '</p>',
    ];

    $prefix = $this->config('shortlink_manager.settings')->get('path_prefix') ?? 'go';
    $prefix_sample_url = Url::fromUserInput('/' . $prefix . '/xE4_iqh', ['absolute' => TRUE])->toString();
    $description = $this->t('The path prefix used by shortlinks. Example: In the URL @url,
<strong><em>@prefix</em></strong> would be the prefix. Do not include the leading forward slash ("/").',
      ['@url' => $prefix_sample_url, '@prefix' => $prefix]);

    $form['path_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Path prefix'),
      '#description' => $description,
      '#default_value' => $prefix,
      '#required' => TRUE,
      '#size' => 20,
      '#maxlength' => 8,
    ];

    $form['path_length'] = [
      '#type' => 'number',
      '#title' => $this->t('Path length'),
      '#description' => $this->t('The number of random characters to generate for shortlink paths. Longer paths reduce collision probability.'),
      '#default_value' => $this->config('shortlink_manager.settings')->get('path_length') ?? 6,
      '#required' => TRUE,
      '#min' => 4,
      '#max' => 12,
    ];

    $form['redirect_status'] = [
      '#title' => $this->t('Redirect status'),
      '#type' => 'select',
      '#options' => [
        '301' => $this->t('301 - Moved permanently'),
        '307' => $this->t('307 - Temporary redirect'),
        '308' => $this->t('308 - Permanent redirect'),
      ],
      '#default_value' => $this->config('shortlink_manager.settings')->get('redirect_status') ?? '301',
      '#description' => $this->t('HTTP redirect status.'),
      '#required' => TRUE,
    ];

    $entity_type_definitions = $this->entityTypeManager->getDefinitions();
    $options = [];
    foreach ($entity_type_definitions as $entity_type_id => $definition) {
      // Check if the entity type has a bundle key defined.
      // This is the most reliable way to find content entities with bundles.
      if ($definition->getKey('bundle')) {
        $options[$entity_type_id] = $definition->getLabel();
      }
    }
    asort($options);

    $form['available_entity_types'] = [
      '#type' => 'select',
      '#title' => $this->t('Available entity types for shortlink generation'),
      '#description' => $this->t('Select the entity types for which shortlinks can be automatically generated.'),
      '#options' => $options,
      '#multiple' => TRUE,
      '#size' => 10,
      '#default_value' => $this->config('shortlink_manager.settings')
        ->get('available_entity_types') ?: [],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $prefix = $form_state->getValue('path_prefix');
    // Strip the leading slash if present.
    if (substr($prefix, 0, 1) === '/') {
      $prefix = substr($prefix, 1);
    }
    $this->config('shortlink_manager.settings')
      ->set('path_prefix', $prefix)
      ->set('path_length', (int) $form_state->getValue('path_length'))
      ->set('redirect_status', $form_state->getValue('redirect_status'))
      ->set('available_entity_types', $form_state->getValue('available_entity_types'))
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['shortlink_manager.settings'];
  }

}
