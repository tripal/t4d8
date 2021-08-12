<?php

namespace Drupal\tripal_chado\Services;

use Drupal\Core\Database\Database;
use Drupal\tripal\Services\bulkPgSchemaInstaller;

class chadoIntegrator extends bulkPgSchemaInstaller {

  /**
   * Integrate a given Chado schema into Tripal.
   *
   * @param string $chado_schema
   *   The schema name (unquoted) of the existing Chado schema to integrate into
   *   Tripal.
   */
  public function import($chado_schema) {
    $connection = $this->connection;

    // VALIDATION.
    // Check the schema name is valid.
    $schema_issue = \Drupal\tripal_chado\api\ChadoSchema::isInvalidSchemaName($chado_schema);
    if ($schema_issue) {
      $this->logger->error($schema_issue);
      return FALSE;
    }
    // Check if the schema exists.
    if (!$this->checkSchema($chado_schema)) {
      $this->logger->error('The schema "' . $chado_schema . '" does not exist. Please select an existing schema to import.');
      return FALSE;
    }
    // Check Chado version.
    $version = 0;
    $sql = "
      SELECT true
      FROM pg_tables
      WHERE
        schemaname = :schema
        AND tablename = 'chadoprop'
    ;";
    $prop_exists = \Drupal::database()->query(
      $sql,
      [':schema' => $chado_schema]
    )->fetchField();

    if ($prop_exists) {
      $chado_schema = $chado_schema;
      $sql = "
        SELECT value
        FROM $chado_schema.chadoprop cp
          INNER JOIN $chado_schema.cvterm cvt ON cvt.cvterm_id = cp.type_id
          INNER JOIN $chado_schema.cv CV ON cvt.cv_id = cv.cv_id
        WHERE
          cv.name = 'chado_properties'
          AND cvt.name = 'version'
        ;
      ";
      $results = \Drupal::database()->query($sql);
      $v = $results->fetchObject();
      if ($v) {
        $version = $v->value;
      }
    }
    if (!$version || (!preg_match('/^1.3/', $version))) {
      $this->logger->error('The Chado version of the schema "' . $chado_schema . '" is not supported. Try upgrading that schema first.');
      return FALSE;
    }

    // Check the schema is not already integrated with Tripal.
    $install_select = \Drupal::database()->select('chado_installations' ,'i')
      ->fields('i', ['install_id'])
      ->condition('schema_name', $chado_schema)
      ->execute();
    $results = $install_select->fetchAll();
    if ($results) {
      $this->logger->error('The schema "' . $chado_schema . '" is already integrated into Tripal and does not need to be imported.');
      return FALSE;
    }

    // Finally set the version and tell Tripal.
    $this->connection->insert('chado_installations')
      ->fields([
        'schema_name' => $chado_schema,
        'version' => $version,
        'created' => \Drupal::time()->getRequestTime(),
        'updated' => \Drupal::time()->getRequestTime(),
      ])
      ->execute();
  }

}
