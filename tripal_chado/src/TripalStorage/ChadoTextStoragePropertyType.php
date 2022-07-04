<?php

namespace Drupal\tripal_chado\TripalStorage;

use Drupal\tripal\TripalStorage\TextStoragePropertyType;

/**
 * Defines the variable character Tripal storage property type.
 */
class ChadoTextStoragePropertyType extends TextStoragePropertyType {

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
   */
  public function __construct($entityType, $fieldType, $key) {
    parent::__construct($entityType, $fieldType, $key);
    $this->setMapping();    
    $this->verifyTableColumn();
  }


}