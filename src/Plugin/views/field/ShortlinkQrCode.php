<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager\Plugin\views\field;

use Drupal\Core\Url;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Renders a QR code download link for a shortlink.
 *
 * @ViewsField("shortlink_qr_code")
 */
class ShortlinkQrCode extends FieldPluginBase {

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
    if (!$entity) {
      return [];
    }

    $url = Url::fromRoute('shortlink_manager.qr_download', [
      'shortlink' => $entity->id(),
    ]);

    return [
      '#type' => 'link',
      '#title' => $this->t('Download QR'),
      '#url' => $url,
      '#attributes' => [
        'class' => ['shortlink-qr-download'],
        'target' => '_blank',
      ],
    ];
  }

}
