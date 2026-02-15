<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\shortlink_manager\ShortlinkClickTracker;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a shortlink analytics dashboard block.
 *
 * @Block(
 *   id = "shortlink_manager_dashboard",
 *   admin_label = @Translation("Shortlink Dashboard"),
 *   category = @Translation("Shortlink Manager")
 * )
 */
class ShortlinkDashboardBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The click tracker service.
   *
   * @var \Drupal\shortlink_manager\ShortlinkClickTracker
   */
  protected ShortlinkClickTracker $clickTracker;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ShortlinkClickTracker $click_tracker,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->clickTracker = $click_tracker;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('shortlink_manager.click_tracker'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    return AccessResult::allowedIfHasPermission($account, 'view shortlink dashboard');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $now = \Drupal::time()->getRequestTime();
    $thirty_days_ago = $now - (30 * 86400);

    $total_clicks = $this->clickTracker->getTotalClicks($thirty_days_ago, $now);
    $top_shortlinks = $this->clickTracker->getTopShortlinks(10, $thirty_days_ago, $now);
    $recent_clicks = $this->clickTracker->getRecentClicks(10);

    // Build top shortlinks table.
    $top_rows = [];
    $storage = $this->entityTypeManager->getStorage('shortlink');
    foreach ($top_shortlinks as $item) {
      $shortlink = $storage->load($item->shortlink_id);
      $top_rows[] = [
        $shortlink ? $shortlink->label() : $this->t('Deleted (#@id)', ['@id' => $item->shortlink_id]),
        $shortlink ? $shortlink->getPath() : '-',
        $item->click_count,
      ];
    }

    // Build recent clicks table.
    $recent_rows = [];
    foreach ($recent_clicks as $click) {
      $shortlink = $storage->load($click->shortlink_id);
      $recent_rows[] = [
        $shortlink ? $shortlink->label() : $this->t('#@id', ['@id' => $click->shortlink_id]),
        \Drupal::service('date.formatter')->format((int) $click->timestamp, 'short'),
        $click->referrer ? mb_substr($click->referrer, 0, 50) : '-',
      ];
    }

    return [
      '#theme' => 'shortlink_manager_dashboard',
      '#total_clicks' => $total_clicks,
      '#top_shortlinks' => $top_rows,
      '#recent_clicks' => $recent_rows,
      '#cache' => [
        'max-age' => 300,
      ],
    ];
  }

}
