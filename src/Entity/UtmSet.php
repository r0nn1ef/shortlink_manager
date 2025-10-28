<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\shortlink_manager\UtmSetInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines the UTM Set config entity.
 *
 * @ConfigEntityType(
 *   id = "utm_set",
 *   label = @Translation("UTM Set"),
 *   label_collection = @Translation("UTM Sets"),
 *   label_singular = @Translation("utm set"),
 *   label_plural = @Translation("utm sets"),
 *   label_count = @PluralTranslation(
 *     singular = "@count UTM set",
 *     plural = "@count UTM sets",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\shortlink_manager\UtmSetListBuilder",
 *     "form" = {
 *       "add" = "Drupal\shortlink_manager\Form\UtmSetForm",
 *       "edit" = "Drupal\shortlink_manager\Form\UtmSetForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *     },
 *   },
 *   config_prefix = "utm_set",
 *   admin_permission = "administer utm_set",
 *   links = {
 *     "collection" = "/admin/structure/utm-set",
 *     "add-form" = "/admin/structure/utm-set/add",
 *     "edit-form" = "/admin/structure/utm-set/{utm_set}",
 *     "delete-form" = "/admin/structure/utm-set/{utm_set}/delete",
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "description",
 *     "utm_source",
 *     "utm_medium",
 *     "utm_campaign",
 *     "utm_term",
 *     "utm_content",
 *     "custom_parameters",
 *     "status",
 *   },
 * )
 */
final class UtmSet extends ConfigEntityBase implements UtmSetInterface, ContainerInjectionInterface {

  /**
   * The UTM set ID.
   */
  protected string $id;

  /**
   * The UTM set label.
   */
  protected string $label;

  /**
   * Description of this UTM set.
   */
  protected string $description = '';

  /**
   * UTM source parameter value.
   */
  protected string $utm_source = '';

  /**
   * UTM medium parameter value.
   */
  protected string $utm_medium = '';

  /**
   * UTM campaign parameter value.
   */
  protected string $utm_campaign = '';

  /**
   * UTM term parameter value.
   */
  protected string $utm_term = '';

  /**
   * UTM content parameter value.
   */
  protected string $utm_content = '';

  /**
   * @var array Custom UTM parameters in the form of 'key' => 'value'.
   */
  protected array $custom_parameters = [];

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Constructs a new UtmSet object.
   *
   * @param array $values
   *   An array of settings.
   * @param string $entity_type
   *   The entity type ID.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   */
  public function __construct(array $values, $entity_type, ModuleHandlerInterface $module_handler) {
    parent::__construct($values, $entity_type);
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $values = []) {
    // The entity ID 'utm_set' is passed to the constructor.
    // The $values array (passed into create) is also passed to the constructor.
    return new static(
      $values,
      'utm_set',
      $container->get('module_handler')
    );
  }

  /**
   * {@inheritDoc}
   */
  public function getStatus(): bool {
    return (bool) $this->status;
  }

  /**
   * {@inheritdoc}
   */
  public function getUtmSource(): string {
    return $this->utm_source;
  }

  /**
   * {@inheritdoc}
   */
  public function getUtmMedium(): string {
    return $this->utm_medium;
  }

  /**
   * {@inheritdoc}
   */
  public function getUtmCampaign(): string {
    return $this->utm_campaign;
  }

  /**
   * {@inheritdoc}
   */
  public function getUtmTerm(): string {
    return $this->utm_term;
  }

  /**
   * {@inheritdoc}
   */
  public function getUtmContent(): string {
    return $this->utm_content;
  }

  /**
   * {@inheritdoc}
   */
  public function getCustomParameters(): array {
    return $this->custom_parameters;
  }

  /**
   * {@inheritdoc}
   */
  public function setCustomParameters(array $custom_parameters): self {
    $this->set('custom_parameters', $custom_parameters);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getUtmParameters(): array {
    $parameters = [];

    $parameters['utm_source'] = $this->getUtmSource();
    $parameters['utm_medium'] = $this->getUtmMedium();
    $parameters['utm_campaign'] = $this->getUtmCampaign();
    $parameters['utm_term'] = $this->getUtmTerm();
    $parameters['utm_content'] = $this->getUtmContent();

    if(!empty($this->getCustomParameters())) {
      $custom_parameters = $this->getCustomParameters();
      foreach ($custom_parameters as $parameter_string) {
        $matches = [];
        // Regex: Matches everything before the FIRST colon (the key) and
        // everything after it (the value).
        $pattern = '/^([^:]+):(.+)$/';

        if (preg_match($pattern, $parameter_string, $matches)) {
          $key = trim($matches[1]);
          $value = trim($matches[2]);
          // Assign the split key/value to the parameters.
          // NOTE: The token replacement for $value must happen later
          // in your code!
          $parameters[$key] = $value;
        }
        // TODO: Add logging/error handling for misformatted parameters here.
      }
    }

    /*
     * Allow other modules to modify the parameters prior to sending them back
     * to the calling code.
     */
    $this->moduleHandler->alter(
      'shortlink_manager_utm_parameters',
      $parameters,
      $this
    );

    return $parameters;
  }

}
