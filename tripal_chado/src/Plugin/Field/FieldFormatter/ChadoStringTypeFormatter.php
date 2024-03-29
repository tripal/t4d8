<?php

namespace Drupal\tripal_chado\Plugin\Field\FieldFormatter;

use Drupal\tripal\Plugin\Field\FieldFormatter\DefaultTripalStringTypeFormatter;
use Drupal\tripal\TripalField\TripalFormatterBase;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of default Chado string type formatter.
 *
 * @FieldFormatter(
 *   id = "chado_string_type_formatter",
 *   label = @Translation("Chado String Type Formatter"),
 *   description = @Translation("The Chado string type formatter."),
 *   field_types = {
 *     "chado_string_type"
 *   }
 * )
 */
class ChadoStringTypeFormatter extends DefaultTripalStringTypeFormatter {

  /**
   * {@inheritDoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    return parent::viewElements($items, $langcode);
  }
}
