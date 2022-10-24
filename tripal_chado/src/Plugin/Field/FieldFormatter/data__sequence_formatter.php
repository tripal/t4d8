<?php

namespace Drupal\tripal_chado\Plugin\Field\FieldFormatter;

use Drupal\tripal\TripalField\TripalFormatterBase;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Plugin implementation of default data sequence type formatter.
 *
 * @FieldFormatter(
 *   id = "data__sequence_formatter",
 *   label = @Translation("Chado Data Sequence Formatter"),
 *   description = @Translation("A chado data sequence formatter"),
 *   field_types = {
 *     "data__sequence"
 *   }
 * )
 */
class data__sequence_formatter extends TripalFormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    // If there are no items, we don't want to return any markup.
    if (count($items) == 0 or (count($items) == 1 and empty($items[0]['value']))) {
      $element[0] = [
        '#markup' => 'No sequence is available.'
      ];
      return;
    }

    $num_bases = 50;
    $content = '<pre class="residues-formatter">';
    $content .= wordwrap($items[0]['value'], $num_bases, "<br>", TRUE);
    $content .= '</pre>';
    $element[0] = [
      '#markup' => $content
    ];

    return $elements;
  }
}
