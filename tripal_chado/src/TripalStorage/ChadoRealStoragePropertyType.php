<?php

namespace Drupal\tripal_chado\TripalStorage;

use Drupal\tripal\TripalStorage\RealStoragePropertyType;

/**
 * Defines the variable character Tripal storage property type.
 */
class ChadoRealStoragePropertyType extends RealStoragePropertyType {

  use ChadoStoragePropertyTypeTrait;
  
  /**
   * Constructs a new variable character tripal storage property type.
   *
   * @param string entityType
   *   The entity type associated with this property type.
   * @param string fieldType
   *   The field type associated with this property type.
   * @param string key
   *   The key associated with this property type.
   * @param int size
   *   The maximum size of characters for this type.
   */
  public function __construct($entityType, $fieldType, $key) {
    parent::__construct($entityType, $fieldType, $key);
    $this->setMapping();    
    $this->verifyTableColumn();
  }


}