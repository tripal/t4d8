<?php

namespace Drupal\tripal_chado\Plugin\Field\FieldType;

use Drupal\tripal\TripalField\TripalFieldItemBase;
use Drupal\tripal\TripalStorage\IntStoragePropertyType;
use Drupal\tripal\TripalStorage\StoragePropertyValue;
use Drupal\core\Form\FormStateInterface;
use Drupal\core\Field\FieldDefinitionInterface;
use Drupal\tripal_chado\TripalField\ChadoFieldItemBase;

/**
 * Plugin implementation of the 'integer' field type for Chado.
 *
 * @FieldType(
 *   id = "chado_integer_type",
 *   label = @Translation("Chado Integer Field Type"),
 *   description = @Translation("An integer field."),
 *   default_widget = "chado_integer_type_widget",
 *   default_formatter = "chado_integer_type_formatter"
 * )
 */
class ChadoIntegerTypeItem extends ChadoFieldItemBase {

  public static $id = "chado_integer_type";


  /**
   * {@inheritdoc}
   */
  public static function tripalTypes($field_definition) {
    $entity_type_id = $field_definition->getTargetEntityTypeId();
    $settings = $field_definition->getSetting('storage_plugin_settings');
    $value_settings = $settings['property_settings']['value'];
    $types = [
      new IntStoragePropertyType($entity_type_id, self::$id, "value", $value_settings),
    ];
    $default_types = TripalFieldItemBase::defaultTripalTypes($entity_type_id, self::$id);
    $types = array_merge($types, $default_types);
    return $types;
  }

  /**
   * {@inheritdoc}
   */
  public function tripalValuesTemplate($field_definition) {
    $entity = $this->getEntity();
    $entity_type_id = $entity->getEntityTypeId();
    $entity_id = $entity->id();
    $values = [
      new StoragePropertyValue($entity_type_id, self::$id, "value", $entity_id),
    ];
    $default_values = TripalFieldItemBase::defaultTripalValuesTemplate($entity_type_id, self::$id, $entity_id);
    $values = array_merge($values, $default_values);
    return $values;
  }

}