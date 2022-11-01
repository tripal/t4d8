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
    $settings = $this->field['settings']; //??
    $field_name = $this->field['field_name']; //??
    $field_type = $this->field['type']; //??
    $field_table = $this->instance['settings']['chado_table']; //??
    $field_column = $this->instance['settings']['chado_column']; //??

    // Get the field defaults.
    $residues = '';
    if (count($items) > 0 and array_key_exists('value', $items[0])) {
      $residues = $items[0]['value'];
    }
    if (array_key_exists('values', $form_state) and
      array_key_exists($field_name, $form_state['values'])) {
      $residues = $form_state['values'][$field_name][$langcode][$delta]['value'];
    }

    $element['value'] = [
      '#type' => 'value',
      '#value' => $residues,
    ];

    $element['chado-' . $field_table . '__' . $field_column] = [
      '#type' => 'textarea',
      '#title' => $element['#title'],
      '#description' => $element['#description'],
      '#weight' => isset($element['#weight']) ? $element['#weight'] : 0,
      '#default_value' => $residues,
      '#delta' => $delta,
      '#cols' => 30,
    ];

    return $element + parent::formElement($items, $delta, $element, $form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $field_name = $this->field['field_name']; //??
    $field_table = $this->instance['settings']['chado_table']; //??
    $field_column = $this->instance['settings']['chado_column']; //??

    // Remove any white spaces.
    $residues = $form_state['values'][$field_name]['und'][$delta]['chado-' . $field_table . '__' . $field_column];
    if ($residues) {
      $residues = preg_replace('/\s/', '', $residues);
      $form_state['values'][$field_name]['und'][$delta]['value'] = $residues;
      $form_state['values'][$field_name]['und'][$delta]['chado-' . $field_table . '__' . $field_column] = $residues;
    }
    // If the residue information has been removed then we want to signal such.
    // When it's removed the value != residues but if it was never set then they're both empty.
    elseif (!empty($form_state['values'][$field_name]['und'][$delta]['value'])) {
      $form_state['values'][$field_name]['und'][$delta]['value'] = 'delete_me';
      $form_state['values'][$field_name]['und'][$delta]['chado-' . $field_table . '__' . $field_column] = '';
    }
  }
}
