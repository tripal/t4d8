<?php

namespace Drupal\tripal_chado\Plugin\Field\FieldType;

use Drupal\tripal_chado\TripalField\ChadoFieldItemBase;
use Drupal\tripal_chado\TripalStorage\ChadoTextStoragePropertyType;
use Drupal\tripal\TripalStorage\StoragePropertyValue;

/**
 * Plugin implementation of Tripal data sequence.
 *
 * @FieldType(
 *   id = "data__sequence",
 *   label = @Translation("Chado Data Sequence"),
 *   description = @Translation("A chado data sequence"),
 *   default_widget = "data__sequence_widget",
 *   default_formatter = "data__sequence_formatter"
 * )
 */
class data__sequence extends ChadoFieldItemBase {

  public static $id = "data__sequence";

  /**
   * {@inheritdoc}
   */
  public static function defaultFieldSettings() {
    $settings = parent::defaultFieldSettings();
    $settings['termIdSpace'] = 'data';
    $settings['termAccession'] = '2044';
    return $settings;
  }

  /**
   * {@inheritdoc}
   */
  public static function tripalTypes($field_definition) {
    $entity_type_id = $field_definition->getTargetEntityTypeId();

    // Indicate the action to perform for each property.
    $settings = $field_definition->getSetting('storage_plugin_settings');
    $value_settings = $settings['property_settings']['value'];

    // Create the property types.
    $data = new ChadoTextStoragePropertyType($entity_type_id, self::$id, 'value', $value_settings);

    // Return the list of property types.
    $types = [$data];
    $default_types = ChadoFieldItemBase::defaultTripalTypes($entity_type_id, self::$id);
    $types = array_merge($types, $default_types);
    return $types;
  }


  /**
   * {@inheritdoc}
   */
  public function tripalValuesTemplate() {

    $entity = $this->getEntity();
    $entity_type_id = $entity->getEntityTypeId();
    $entity_id = $entity->id();

    // Build the values array.
    $values = [
      new StoragePropertyValue($entity_type_id, self::$id, 'value', $entity_id),
    ];
    $default_values = ChadoFieldItemBase::defaultTripalValuesTemplate($entity_type_id, self::$id, $entity_id);

    $values = array_merge($values, $default_values);
    return $values;
  }
}
