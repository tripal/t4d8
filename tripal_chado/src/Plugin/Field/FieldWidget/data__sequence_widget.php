<?php

namespace Drupal\tripal_chado\Plugin\Field\FieldWidget;

use Drupal\tripal\TripalField\TripalWidgetBase;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of default data sequence type widget.
 *
 * @FieldWidget(
 *   id = "data__sequence_widget",
 *   label = @Translation("Chado Data Sequence Widget"),
 *   description = @Translation("A chado data sequence widget"),
 *   field_types = {
 *     "data__sequence"
 *   }
 * )
 */
class data__sequence_widget extends TripalWidgetBase {


  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {

    $element['value'] = [
      '#type' => 'textarea',
      '#default_value' => $items[$delta]['value'],
      '#required' => $items[$delta]->getSetting('required');
    ];

    return $element + parent::formElement($items, $delta, $element, $form, $form_state);
  }
}
