<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * Provides a listing of shortlinks.
 */
final class ShortlinkListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['id'] = $this->t('ID');
    $header['label'] = $this->t('Label');
    $header['path'] = $this->t('Shortlink');
    $header['status'] = $this->t('Status');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\shortlink_manager\Entity\Shortlink $entity */
    $row['id'] = $entity->id();
    $row['label'] = $entity->toLink(html_entity_decode($entity->label()));
    $row['path'] = $entity->getPath();
    $row['status'] = $entity->isEnabled() ? $this->t('Enabled') : $this->t('Disabled');
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   *
   * Overrides the entity listing as renderable array for table.html.twig
   * and sets a new empty message.
   */
  public function render(): array {
    $build['table'] = parent::render();
    $build['table']['#empty'] = $this->t('No @label have been created yet.', ['@label' => ($this->entityType->getLabel() . 's')]);
    return $build;
  }

}
