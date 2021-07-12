<?php

namespace Drupal\tripal_chado\Services;

use Drupal\Core\Database\Database;
use Drupal\tripal\Services\bulkPgSchemaInstaller;

class chadoCloner extends bulkPgSchemaInstaller {

  /**
   * Clone a given chado schema into the specified schema.
   *
   * The cloning procedure uses a custom postgreSQL function
   * (tripal_clone_schema())to clone schema rather than using a schema dump,
   * modifying it to change schema name (with possible side effects) and
   * reloading that dump. It is faster and avoids using temporary files (risks
   * of content disclosure).
   *
   * @param string $source_schema
   *   The source schema to clone into $this->schemaName.
   */
  public function cloneSchema($source_schema) {

    $target_schema = $this->schemaName;
    $connection = $this->connection;

    // VALIDATION.
    // Check the schema names are valid.
    $schema_issue = \Drupal\tripal_chado\api\ChadoSchema::isInvalidSchemaName($source_schema);
    if ($schema_issue) {
      $this->logger->error("An error occurred on source schema to clone:\n" . $schema_issue);
      return FALSE;
    }
    $schema_issue = \Drupal\tripal_chado\api\ChadoSchema::isInvalidSchemaName($target_schema);
    if ($schema_issue) {
      $this->logger->error("An error occurred on target schema:\n" . $schema_issue);
      return FALSE;
    }

    // Check if the target schema exists or is free.
    if ($this->checkSchema($target_schema)) {
      $this->logger->error('Target schema "' . $target_schema . '" already exists. Please remove that schema first.');
      return FALSE;
    }

    // Check if the source schema exists.
    if (!$this->checkSchema($source_schema)) {
      $this->logger->error('The source schema to clone "' . $source_schema . '" does not exist. Please select an existing schema to clone.');
      return FALSE;
    }

    // Clone schema.
    $sql = "SELECT public.tripal_clone_schema('$source_schema', '$target_schema', TRUE, FALSE);";
    $this->connection->query($sql);
    $this->logger->info("Schema cloning completed\n");
  }

}
