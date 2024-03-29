<?php

namespace Drupal\tripal\ListBuilders;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Link;
use Drupal\Core\Url;

/**
 * Defines a class to build a listing of Tripal Content entities.
 *
 * @ingroup tripal
 */
class TripalEntityListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['title'] = $this->t('Title');
    $header['type'] = $this->t('Type');
    $header['term'] = $this->t('Term');
    $header['author'] = $this->t('Author');
    $header['created'] = $this->t('Created');

    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {

    $type_name = $entity->getType();
    $bundle = \Drupal\tripal\Entity\TripalEntityType::load($type_name);

    $row['title'] = Link::fromTextAndUrl(
      $entity->getTitle(),
      $entity->toUrl('canonical', ['tripal_entity' => $entity->id()])
    )->toString();

    $row['type'] = $bundle->getLabel();
    $row['term'] = '';

    $row['author'] = '';
    $owner = $entity->getOwner();
    if ($owner) {
      $row['author'] = $owner->getDisplayName();
    }

    $row['created'] = \Drupal::service('date.formatter')->format($entity->getCreatedTime());

    return $row + parent::buildRow($entity);
  }

}
