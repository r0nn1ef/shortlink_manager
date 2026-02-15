<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;

/**
 * Service for generating QR codes for shortlinks.
 */
class ShortlinkQrGenerator {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Constructs a ShortlinkQrGenerator service.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * Generates a QR code PNG for a shortlink.
   *
   * @param \Drupal\shortlink_manager\ShortlinkInterface $shortlink
   *   The shortlink entity.
   * @param int $size
   *   The QR code size in pixels.
   *
   * @return string
   *   The PNG binary data.
   */
  public function generateQrCode(ShortlinkInterface $shortlink, int $size = 300): string {
    $url = $this->getShortlinkAbsoluteUrl($shortlink);

    $result = Builder::create()
      ->writer(new PngWriter())
      ->data($url)
      ->encoding(new Encoding('UTF-8'))
      ->errorCorrectionLevel(ErrorCorrectionLevel::Medium)
      ->size($size)
      ->margin(10)
      ->build();

    return $result->getString();
  }

  /**
   * Generates a base64 data URI for inline QR code display.
   *
   * @param \Drupal\shortlink_manager\ShortlinkInterface $shortlink
   *   The shortlink entity.
   * @param int $size
   *   The QR code size in pixels.
   *
   * @return string
   *   The base64-encoded data URI.
   */
  public function getQrCodeDataUri(ShortlinkInterface $shortlink, int $size = 150): string {
    $png = $this->generateQrCode($shortlink, $size);
    return 'data:image/png;base64,' . base64_encode($png);
  }

  /**
   * Gets the absolute URL for a shortlink.
   *
   * @param \Drupal\shortlink_manager\ShortlinkInterface $shortlink
   *   The shortlink entity.
   *
   * @return string
   *   The absolute URL.
   */
  protected function getShortlinkAbsoluteUrl(ShortlinkInterface $shortlink): string {
    $path = $shortlink->getPath();
    return Url::fromUserInput('/' . $path, ['absolute' => TRUE])->toString();
  }

}
