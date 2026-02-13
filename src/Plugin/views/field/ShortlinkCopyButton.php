<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager\Plugin\views\field;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders a copy-to-clipboard button for a shortlink.
 *
 * @ViewsField("shortlink_copy_button")
 */
class ShortlinkCopyButton extends FieldPluginBase {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->configFactory = $container->get('config.factory');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    // This field does not query the database.
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values): array {
    $entity = $this->getEntity($values);
    if (!$entity || !$entity->hasField('path')) {
      return [];
    }

    $path = $entity->get('path')->value;
    if (empty($path)) {
      return [];
    }

    $full_url = Url::fromUserInput('/' . $path, ['absolute' => TRUE])->toString();

    return [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => $this->t('Copy'),
      '#attributes' => [
        'class' => ['shortlink-copy-btn'],
        'data-shortlink-url' => $full_url,
        'type' => 'button',
      ],
      '#attached' => [
        'library' => ['shortlink_manager/clipboard'],
      ],
    ];
  }

}
