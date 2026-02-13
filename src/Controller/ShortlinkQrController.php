<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\shortlink_manager\Entity\Shortlink;
use Drupal\shortlink_manager\ShortlinkQrGenerator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for QR code downloads.
 */
final class ShortlinkQrController extends ControllerBase {

  /**
   * The QR generator service.
   *
   * @var \Drupal\shortlink_manager\ShortlinkQrGenerator
   */
  protected ShortlinkQrGenerator $qrGenerator;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->qrGenerator = $container->get('shortlink_manager.qr_generator');
    return $instance;
  }

  /**
   * Downloads a QR code PNG for a shortlink.
   *
   * @param \Drupal\shortlink_manager\Entity\Shortlink $shortlink
   *   The shortlink entity.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response containing the PNG image.
   */
  public function downloadQr(Shortlink $shortlink): Response {
    $png = $this->qrGenerator->generateQrCode($shortlink, 300);
    $filename = 'shortlink-' . $shortlink->id() . '-qr.png';

    $response = new Response($png);
    $response->headers->set('Content-Type', 'image/png');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
    $response->headers->set('Content-Length', (string) strlen($png));

    return $response;
  }

}
