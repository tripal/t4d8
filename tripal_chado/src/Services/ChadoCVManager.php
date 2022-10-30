<?php

namespace Drupal\tripal_chado\Services;

use Exception;

class ChadoCVManager {

  /**
   * Instantiates a new ChadoTermsInit object.
   */
  public function __construct() {

  }

  /**
   * Thie function should insert a cvterm into the chado database
   * @param mixed $term 
   * @param array $options 
   * @param string $schema_name 
   * @return void 
   * @throws Exception 
   */
  public function insert_cvterm($term, $options = [], $schema_name = 'chado') {
    $chado = \Drupal::service('tripal_chado.database');
    $chado->setSchemaName($schema_name);

    $fields = [];

    // Check if cv_name exists in database and get id
    $cv_results = $chado->select('cv')
      ->fields('cv',['cv_id'])
      ->condition('cv.name', $term->cv_name)
      ->execute();
    if(!$cv_results) {
      throw new \Exception('CV ' . $term->cv_name . ' does not exist');
    }
    $cv_id = $cv_results->fetchObject()->cv_id;


    // Check if db_name exists in database and get id
    $db_results = $chado->select('db')
      ->fields('db',['db_id'])
      ->condition('db.name', $term->db_name)
      ->execute();
    if(!$db_results) {
      throw new \Exception('DB ' . $term->db_name . ' does not exist');
    }
    $db_id = $db_results->fetchObject()->db_id;

    // Insert the cvterm into the database

  }
  
}