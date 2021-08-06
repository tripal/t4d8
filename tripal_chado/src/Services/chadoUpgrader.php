<?php

namespace Drupal\tripal_chado\Services;

use Drupal\Core\Database\Database;
use Drupal\tripal\Services\bulkPgSchemaInstaller;

class chadoUpgrader extends bulkPgSchemaInstaller {

  /**
   * Name of the reference schema for 1.3.
   *
   * This name can be overriden by extending classes.
   * We use the ¥ sign (UTF8 \xC2\xA5 or ASCII \xBE) to reduce the risk of
   * schema name conflict.
   */
  public const CHADO_REF_SCHEMA = '_chado_upgrade_tmp_¥';

  /**
   * Defines a priority order to process some Chado objects to upgrade.
   */
  public const CHADO_OBJECTS_PRIORITY = [
    'db',
    'dbxref',
    'cv',
    'cvterm',
    'cvtermpath',
    'pub',
    'synonym',
    'feature',
    'feature_cvterm',
    'feature_dbxref',
    'feature_synonym',
    'featureprop',
    'feature_pub',
    'gffatts',

  /*
    cvtermpath / _get_all_object_ids(bigint)
    cvtermpath / _get_all_subject_ids(bigint)
    feature / feature_disjoint_from(bigint)
    feature / feature_overlaps(bigint)
    featureloc / feature_subalignments(bigint)
    ...

  */
  ];

  /**
   * The version of the current and new chado schema specified by $schemaName.
   */
  protected $newVersion;

  /**
   * PostgreSQL-quoted schema name.
   */
  protected $schemaNameQuoted;

  /**
   * PostgreSQL-quoted reference schema name.
   */
  protected $refSchemaNameQuoted;

  /**
   * Upgrade SQL queries.
   */
  protected $upgradeQueries;

  /**
   * Database object dependencies.
   */
  protected $dependencies;

  /**
   * Upgrade Chado schema to the specified version.
   *
   * We create a new Chado 1.3 schema  (@see CHADO_REF_SCHEMA) to use as a
   * reference for the upgrade process. Then, we process each PostgreSQL object
   * categories and compare the schema to upgrade to the reference one. When
   * changes are required, we store the corresponding SQL queries for each
   * object in the 'upgradeQueries' class member. Cleanup queries are stored in
   * 'upgradeQueries['#cleanup']' in order to remove unwanted objects.
   * The upgrade process is the following:
   * 1) Prepare table column defaults removal in table definitions (ie. remove
   *    sequences and function dependencies)
   * 2) Prepare functions and aggregate functions removal
   * 3) Prepare views removal
   * 4) Prepare database type upgrade
   * 5) Prepare sequence upgrade
   * 6) Prepare function prototypes (for function inter-dependencies)
   * 7) Prepare table column type upgrade
   *    Columns that match $chado_column_upgrade will be upgraded
   *    using the corresponding queries. Other columns will be updated using
   *    default behavior. Defaults are dropped and will be added later.
   * 8) Prepare sequence association (to table columns)
   * 9) Prepare view upgrade
   * 10) Prepare function upgrade
   * 11) Prepare aggregate function upgrade
   * 12) Prepare table column default upgrade
   * 13) Prepare comment upgrade
   * 14) Prepare data initialization
   * 15) Process upgrade queries
   * 16) Update Tripal integration if needed.
   *
   * Note: a couple of PostgreSQL object are not processed as they are not part
   * of Chado schema specifications: collations, domains, triggers and
   * materialized views (in PostgreSQL, not Tripal).
   *
   * @param float $version
   *   The version of chado you would like to upgrade to.
   * @param boolean $cleanup
   *   If TRUE, also remove any stuff not in Chado 1.3 schema definition.
   */
  public function upgrade($version, $cleanup = TRUE, $filename = NULL) {
    $this->newVersion = $version;
    // Save schema name to upgrade.
    $chado_schema = $this->schemaName;

    // The ref schema name can be overriden by extending classes.
    $ref_chado_schema = $this::CHADO_REF_SCHEMA;

    $connection = $this->connection;
    // Get quoted schema names from PostgreSQL.
    $sql_query = "SELECT quote_ident(:schema) AS \"qi\";";
    $chado_schema_quoted = $connection
      ->query($sql_query, [':schema' => $chado_schema])
      ->fetch()
      ->qi ?: $chado_schema
    ;
    $this->schemaNameQuoted = $chado_schema_quoted;
    $ref_chado_schema_quoted = $connection
      ->query($sql_query, [':schema' => $ref_chado_schema])
      ->fetch()
      ->qi ?: $ref_chado_schema
    ;
    $this->refSchemaNameQuoted = $ref_chado_schema_quoted;

    // Init query array. We initialize a list of element to have them processed
    // in correct order.
    $this->upgradeQueries = [
      '#start' => ['START TRANSACTION;'],
      '#cleanup' => [],
      '#drop_column_defaults' => [],
      '#drop_functions' => [],
      '#drop_views' => [],
      '#types' => [],
      '#sequences' => [],
      '#priorities' => [],
      // "#end" will be processed at last even if new elements are added after
      // to upgradeQueries. Its queries will be processed in reverse order.
      '#end' => ['COMMIT;'],
    ];

    // VALIDATION.
    // Check the version is valid.
    if (!in_array($version, ['1.3'])) {
      $this->logger->error("That version is not supported by the upgrader.");
      return FALSE;
    }
    // Check if the schema to upgrade exists.
    if (!$this->checkSchema($chado_schema)) {
      $this->logger->error('The schema to upgrade "' . $chado_schema . '" does not exist. Please select an existing schema to upgrade.');
      return FALSE;
    }

    // Make sure the reference schema is available.
    if ($this->checkSchema($ref_chado_schema)) {
      $this->logger->error(
        'Temporary reference schema "'
        . $ref_chado_schema
        . '" already exists. Please remove that schema first if you did not create it (previous unsuccessfull upgrade) or rename it otherwise.'
      );
      return FALSE;
    }
    // Validations ok, save previous search_path.
    $sql_query = "SELECT setting FROM pg_settings WHERE name = 'search_path';";
    $old_search_path = $connection->query($sql_query)->fetch()->setting ?: "''";

    // 1) Create the reference schema.
    $this->createSchema($ref_chado_schema);

    // 2) Apply SQL file containing schema definitions.
    // Set member schema as the reference one.
    $this->schemaName = $ref_chado_schema;
    $module_path = drupal_get_path('module', 'tripal_chado');
    $file_path = $module_path . '/chado_schema/chado-only-' . $version . '.sql';

    // Run SQL file defining Chado schema.
    $success = $this->applySQL($file_path, $ref_chado_schema);
    if ($success) {
      // Initialize schema with minimal data.
      $file_path = $module_path . '/chado_schema/initialize-' . $version . '.sql';
      $success = $this->applySQL($file_path, $ref_chado_schema);
    }
    if (!$success) {
      $this->logger->error(
        'Temporary reference schema "'
        . $ref_chado_schema
        . '" could not be initialized.'
      );
      $this->dropSchema($ref_chado_schema);
      return FALSE;
    }
    // Add version so the UI will detect the correct version if the temporary
    // reference schema is not removed.
    $sql_query = "
      INSERT INTO "
      . $this->refSchemaNameQuoted
      . ".chadoprop (type_id, value)
      VALUES (
        (
          SELECT cvterm_id
          FROM "
      . $this->refSchemaNameQuoted
      . ".cvterm CVT
            INNER JOIN "
      . $this->refSchemaNameQuoted
      . ".cv CV on CVT.cv_id = CV.cv_id
          WHERE CV.name = 'chado_properties' AND CVT.name = 'version'
        ),
        :version
      );
    ";
    $this->connection->query($sql_query, [':version' => $version]);

    try {
      // Put back specified schema name.
      $this->schemaName = $chado_schema;
      // And make sure we workd in this current schema.
      $sql_query = "SET search_path = " . $this->schemaNameQuoted . ",public;";
      $connection->query($sql_query);
      pg_query($this->getPgConnection(), $sql_query);

      // 3) Compare schema structures...
      // - Remove column defaults.
      $this->prepareDropColumnDefaults($chado_schema, $ref_chado_schema);

      // - Remove functions.
      $this->prepareDropFunctions($chado_schema, $ref_chado_schema, $cleanup);

      // - Drop old views to remove dependencies on tables.
      $this->prepareDropAllViews($chado_schema);

      // - Check types.
      $this->prepareUpgradeTypes($chado_schema, $ref_chado_schema, $cleanup);

      // - Upgrade existing sequences and add missing ones.
      $this->prepareUpgradeSequences($chado_schema, $ref_chado_schema, $cleanup);

      // - Create prototype functions.
      $this->preparePrototypeFunctions($chado_schema, $ref_chado_schema);

      // - Tables.
      $this->prepareUpgradeTables($chado_schema, $ref_chado_schema, $cleanup);

      // - Sequence associations.
      $this->prepareSequenceAssociation($chado_schema, $ref_chado_schema);

      // - Views.
      $this->prepareUpgradeViews($chado_schema, $ref_chado_schema, $cleanup);

      // - Upgrade functions (fill function bodies).
      $this->prepareFunctionUpgrade($chado_schema, $ref_chado_schema, $cleanup);

      // - Upgrade aggregate functions.
      $this->prepareAggregateFunctionUpgrade($chado_schema, $ref_chado_schema, $cleanup);

      // - Tables defaults.
      $this->prepareUpgradeTableDefauls($chado_schema, $ref_chado_schema, $cleanup);

      // - Upgrade comments.
      $this->prepareCommentUpgrade($chado_schema, $ref_chado_schema, $cleanup);

      // - Add missing initialization data.
      $this->reinitSchema($chado_schema, $version);

      // - Process upgrades.
      $this->processUpgrades($chado_schema, $filename);

      // @TODO: Test transaction behavior.
      // x) @TODO: Check if schema is integrated into Tripal and update version if needed.

    }
    catch (Exception $e) {
      pg_query($this->getPgConnection(), 'ROLLBACK;');
      // Restore search_path.
      $sql_query = "SET search_path = $old_search_path;";
      $connection->query($sql_query);
      pg_query($this->getPgConnection(), $sql_query);

      // Drop temporary schema.
      $this->dropSchema($ref_chado_schema);

      // Rethrow exception.
      throw $e;
    }

    // x) Restore search_path.
    $sql_query = "SET search_path = $old_search_path;";
    $connection->query($sql_query);
    pg_query($this->getPgConnection(), $sql_query);

    // x) Remove temporary reference schema.
    $this->dropSchema($ref_chado_schema);
  }

  /**
   * Upgrade schema types.
   *
   * @param $chado_schema
   *   Name of the schema to upgrade.
   * @param $ref_chado_schema
   *   Name of the reference schema to use for upgrade.
   * @param $cleanup
   *   Remove types not defined in the official Chado release.
   */
  protected function prepareUpgradeTypes(
    $chado_schema,
    $ref_chado_schema,
    $cleanup
  ) {
    $connection = $this->connection;
    // Here we don't use {} for tables as these are system tables.
    $sql_query = "
      SELECT
        c.relkind,
        t.typname,
        t.typcategory,
        CASE
          WHEN t.typcategory='C' THEN
            array_to_string(
              array_agg(
                a.attname
                || ' '
                || pg_catalog.format_type(a.atttypid, a.atttypmod)
                ORDER BY c.relname, a.attnum
              ),
              ', '
            )
          WHEN t.typcategory = 'E' THEN
            REPLACE(
              quote_literal(
                array_to_string(
                  array_agg(e.enumlabel ORDER BY e.enumsortorder),','
                )
              ),
              ',',
              ''','''
            )
          ELSE ''
        END AS \"typdef\"
      FROM pg_type t
        JOIN pg_namespace n ON (n.oid = t.typnamespace)
        LEFT JOIN pg_enum e ON (t.oid = e.enumtypid)
        LEFT JOIN pg_class c ON (c.reltype = t.oid)
        LEFT JOIN pg_attribute a ON (a.attrelid = c.oid)
      WHERE n.nspname = :schema
        AND (c.relkind IS NULL OR c.relkind = 'c')
        AND t.typcategory IN ('C', 'E')
        GROUP BY 1,2,3
        ORDER BY t.typcategory, t.typname;
    ";
    $old_types = $connection
      ->query($sql_query, [':schema' => $chado_schema])
      ->fetchAllAssoc('typname')
    ;

    $new_types = $connection
      ->query($sql_query, [':schema' => $ref_chado_schema])
      ->fetchAllAssoc('typname')
    ;

    // Check for missing or changed types.
    foreach ($new_types as $new_type_name => $new_type) {
      if (array_key_exists($new_type_name, $old_types)) {
        // Exists, compare.
        $old_type = $old_types[$new_type_name];
        if (($new_type->typcategory != $old_type->typcategory)
            || ($new_type->typdef != $old_type->typdef)) {
          // Recreate type.
          $this->upgradeQueries['#types'][] =
            "DROP TYPE IF EXISTS "
            . $this->schemaNameQuoted
            . ".$new_type_name CASCADE;";
          $this->upgradeQueries['#types'][] =
            "CREATE TYPE "
            . $this->schemaNameQuoted
            . ".$new_type_name AS "
            . ($new_type->typcategory == 'E' ? 'ENUM ' : '')
            . "("
            . $new_type->typdef
            . ");"
          ;
        }
        else {
          // Same types: remove from $new_types.
          unset($new_types[$new_type_name]);
        }
        // Processed: remove from $old_types.
        unset($old_types[$new_type_name]);
      }
      else {
        // Does not exist, add it.
        $this->upgradeQueries['#types'][] =
          "CREATE TYPE "
          . $this->schemaNameQuoted
          . ".$new_type_name AS "
          . ($new_type->typcategory == 'E' ? 'ENUM ' : '')
          . "("
          . $new_type->typdef
          . ");"
        ;
      }
    }
    // Report type changes.
    if (!empty($old_types)) {
      if ($cleanup) {
        // Remove old types.
        foreach ($old_types as $old_type_name => $old_type) {
          $this->upgradeQueries['#cleanup'][] =
            "DROP TYPE IF EXISTS "
            . $this->schemaNameQuoted
            . ".$old_type_name CASCADE;"
          ;
        }
        \Drupal::messenger()->addWarning(
          t(
            "The following schema types have been removed:\n%types",
            ['%types' => implode(', ', array_keys($old_types))]
          )
        );
      }
      else {
        \Drupal::messenger()->addWarning(
          t(
            "The following schema types are not part of the new Chado schema specifications but have been left unchanged. If they are useless, they could be removed:\n%types",
            ['%types' => implode(', ', array_keys($old_types))]
          )
        );
      }
    }
    if (!empty($new_types)) {
      \Drupal::messenger()->addStatus(
        t(
          "The following schema types were upgraded:\n%types",
          ['%types' => implode(', ', array_keys($new_types))]
        )
      );
    }
    if (empty($old_types) && empty($new_types)) {
      \Drupal::messenger()->addStatus(t("All types were already up-to-date."));
    }
  }

  /**
   * Remove table column defaults.
   *
   * Since column defaults may use functions that need to be upgraded, we remove
   * those default in order to drop old functions without removing column
   * content.
   *
   * @param $chado_schema
   *   Name of the schema to upgrade.
   * @param $ref_chado_schema
   *   Name of the reference schema to use for upgrade.
   */
  protected function prepareDropColumnDefaults(
    $chado_schema,
    $ref_chado_schema
  ) {
    $connection = $this->connection;
    // Here we don't use {} for tables as these are system tables.
    // Note: unlogged tables are not considered.
    $sql_query = "
      SELECT
        DISTINCT c.relname
      FROM
        pg_class c
        JOIN pg_namespace n ON (
          n.oid = c.relnamespace
          AND n.nspname = :schema
        )
      WHERE
        c.relkind = 'r'
        AND c.relpersistence = 'p'
        AND EXISTS (
          SELECT TRUE
          FROM pg_class c2
            JOIN pg_namespace n2 ON (
              n2.oid = c2.relnamespace
              AND n2.nspname = :ref_schema
            )
          WHERE c2.relname = c.relname
            AND c2.relkind = 'r'
            AND c2.relpersistence = 'p'
        )
    ";
    // Here, we use ref schema as old schema table should be removed by cleanup.
    $tables = $connection
      ->query(
        $sql_query,
        [':schema' => $chado_schema, ':ref_schema' => $ref_chado_schema,]
      )
      ->fetchCol()
    ;

    foreach ($tables as $table) {
      // Get old table definition.
      $sql_query = "SELECT public.tripal_get_table_ddl(:schema, :table, TRUE) AS \"definition\";";
      $table_raw_definition = explode(
        "\n",
        $connection
          ->query($sql_query, [':schema' => $chado_schema, ':table' => $table])
          ->fetch()
          ->definition
      );
      $table_definition = $this->parse_table_ddl($table_raw_definition);
      foreach ($table_definition['columns'] as $column => $column_def) {
        if (!empty($column_def['default'])) {
          $this->upgradeQueries['#drop_column_defaults'][] =
            "ALTER TABLE "
            . $this->schemaNameQuoted
            . '.'
            . $table
            . " ALTER COLUMN $column DROP DEFAULT;"
          ;
        }
      }
    }
  }

  /**
   * Create prototype functions.
   *
   * Replace existing functions with same signature by protoype functions.
   * Prototype functions are functions with an empty body. Those functions will
   * be filled later with the upgraded content. The idea here is to be able to
   * link those functions in other database objects without having to deal with
   * function inter-dependencies (i.e. empty body, so no dependency inside) and
   * keep the same function reference when it will be upgraded.
   *
   * @param $chado_schema
   *   Name of the schema to upgrade.
   * @param $ref_chado_schema
   *   Name of the reference schema to use for upgrade.
   */
  protected function preparePrototypeFunctions(
    $chado_schema,
    $ref_chado_schema
  ) {
    $connection = $this->connection;

    $sql_query = "
      SELECT
        p.proname AS \"proname\",
        p.proname
          || '('
          ||  pg_get_function_identity_arguments(p.oid)
          || ')'
        AS \"proident\",
        replace(
          'CREATE OR REPLACE FUNCTION " . $this->schemaNameQuoted . ".'
            || quote_ident(p.proname)
            || '('
            ||  pg_get_function_identity_arguments(p.oid)
            || ') RETURNS '
            || pg_get_function_result(p.oid)
            || ' LANGUAGE plpgsql '
            || CASE
                 WHEN p.provolatile = 'i' THEN ' IMMUTABLE'
                 ELSE ''
               END
            || ' AS \$_\$ BEGIN END# \$_\$#',
          '" . $this->refSchemaNameQuoted . ".',
          '" . $this->schemaNameQuoted . ".'
        ) AS \"proto\"
      FROM pg_proc p
        JOIN pg_namespace n ON pronamespace = n.oid
      WHERE
        n.nspname = :ref_schema
        AND prokind != 'a'
      ;
    ";
    $proto_funcs = $connection
      ->query($sql_query, [
        ':ref_schema' => $ref_chado_schema,
      ])
      ->fetchAll()
    ;
    // We use internal PG connection to create functions as function body
    // contains ';' which is forbiden in Drupal DB queries.
    foreach ($proto_funcs as $proto_func) {
      // Drop previous version if exists (as it may have a different return
      // type and cause problems).
      $sql_query = preg_replace(
        '/^.*?FUNCTION\s+((?:[^\.]+\.)?[\w\$\x80-\xFF]+\s*\([^\)]*\)).*$/s',
        'DROP FUNCTION IF EXISTS \1 CASCADE;',
        $proto_func->proto
      );
     $proto_query = str_replace('#', ';', $proto_func->proto);

      $object_id = $proto_func->proident . ' proto';
      if (!isset($this->upgradeQueries[$object_id])) {
        $this->upgradeQueries[$object_id] = [];
      }
      $this->upgradeQueries[$object_id][] = $sql_query;
      $this->upgradeQueries[$object_id][] = $proto_query;
      // $this->dependencies[proto_func->proident] = [];
    }
  }

  /**
   * Upgrade schema sequences.
   *
   * @param $chado_schema
   *   Name of the schema to upgrade.
   * @param $ref_chado_schema
   *   Name of the reference schema to use for upgrade.
   * @param $cleanup
   *   Remove sequences not defined in the official Chado release.
   */
  protected function prepareUpgradeSequences(
    $chado_schema,
    $ref_chado_schema,
    $cleanup
  ) {
    $connection = $this->connection;
    // Here we don't use {} for tables as these are system tables.
    $sql_query = "
      SELECT
        sequence_name,
        start_value,
        minimum_value,
        maximum_value,
        increment,
        cycle_option
      FROM information_schema.sequences
      WHERE sequence_schema = :schema
      ORDER BY 1;
    ";
    $old_seqs = $connection
      ->query($sql_query, [':schema' => $chado_schema])
      ->fetchAllAssoc('sequence_name')
    ;
    $new_seqs = $connection
      ->query($sql_query, [':schema' => $ref_chado_schema])
      ->fetchAllAssoc('sequence_name')
    ;

    // Check for missing or changed sequences.
    foreach ($new_seqs as $new_seq_name => $new_seq) {
      // Prepare creation/update query.
      $increment_sql = (
        $new_seq->increment
        ? ' INCREMENT BY ' . $new_seq->increment
        : ''
      );
      $min_val_sql = (
        $new_seq->minimum_value
        ? ' MINVALUE ' . $new_seq->minimum_value
        : ' NO MINVALUE'
      );
      $max_val_sql = (
        $new_seq->maximum_value
        ? ' MAXVALUE ' . $new_seq->maximum_value
        : ' NO MAXVALUE'
      );
      $start_sql = (
        ($new_seq->start_value != '')
        ? ' START WITH ' . $new_seq->start_value
        : ''
      );
      // We don't manage sequence CACHE here, not set in Chado schema.
      $cycle_sql = (
        ('YES' == $new_seq->cycle_option)
        ? ' CYCLE'
        : ' NO CYCLE'
      );
      // Owning tables are managed later, once tables are upgraded.
      $create_update_seq_sql_query =
        ' SEQUENCE '
        . $this->schemaNameQuoted
        . '.'
        . $new_seq_name
        . $increment_sql
        . $min_val_sql
        . $max_val_sql
        . $start_sql
        . $cycle_sql
      ;

      if (array_key_exists($new_seq_name, $old_seqs)) {
        // Exists, compare.
        $old_seq = $old_seqs[$new_seq_name];

        if (($new_seq->start_value != $old_seq->start_value)
            || ($new_seq->minimum_value != $old_seq->minimum_value)
            || ($new_seq->maximum_value != $old_seq->maximum_value)
            || ($new_seq->increment != $old_seq->increment)
            || ($new_seq->cycle_option != $old_seq->cycle_option)
        ) {

          // Alter sequence.
          $this->upgradeQueries['#sequences'][] =
            'ALTER '
            . $create_update_seq_sql_query
          ;
        }
        else {
          // Same types: remove from $new_seqs.
          unset($new_seqs[$new_seq_name]);
        }
        // Processed: remove from $old_seqs.
        unset($old_seqs[$new_seq_name]);
      }
      else {
        // Does not exist, add it.
        $this->upgradeQueries['#sequences'][] =
          'CREATE '
          . $create_update_seq_sql_query
        ;
      }
    }

    // Report sequence changes.
    if (!empty($old_seqs)) {
      // Remove old sequences.
      if ($cleanup) {
        foreach ($old_seqs as $old_seq_name => $old_seq) {
          $sql_query =
            "DROP SEQUENCE IF EXISTS "
            . $this->schemaNameQuoted
            . ".$old_seq_name CASCADE;"
          ;
          $this->upgradeQueries['#cleanup'][] = $sql_query;
        }
        \Drupal::messenger()->addWarning(
          t(
            "The following sequences have been removed:\n%sequences",
            ['%sequences' => implode(', ', array_keys($old_seqs))]
          )
        );
      }
      else {
        \Drupal::messenger()->addWarning(
          t(
            "The following schema sequences are not part of the new Chado schema specifications but have been left unchanged. If they are useless, they could be removed:\n%sequences",
            ['%sequences' => implode(', ', array_keys($old_seqs))]
          )
        );
      }
    }
    if (!empty($new_seqs)) {
      \Drupal::messenger()->addStatus(
        t(
          "The following schema sequences were upgraded:\n%sequences",
          ['%sequences' => implode(', ', array_keys($new_seqs))]
        )
      );
    }
    if (empty($old_seqs) && empty($new_seqs)) {
      \Drupal::messenger()->addStatus(t("All sequences were already up-to-date."));
    }
  }

 /**
   * Drop all views of schema to upgrade.
   *
   * @param $chado_schema
   *   Name of the schema to upgrade.
   * @param $ref_chado_schema
   *   Name of the reference schema to use for upgrade.
   */
  protected function prepareDropAllViews(
    $chado_schema
  ) {
    $connection = $this->connection;
    // Here we don't use {} for tables as these are system tables.
    $sql_query = "
      SELECT table_name
      FROM information_schema.views
      WHERE table_schema = :schema
      ORDER BY table_name
    ";
    $views = $connection
      ->query($sql_query, [':schema' => $chado_schema])
      ->fetchCol()
    ;
    // Drop all views of the schema.
    foreach ($views as $view) {
      $this->upgradeQueries['#drop_views'][] =
        "DROP VIEW IF EXISTS "
        . $this->schemaNameQuoted
        . ".$view CASCADE;"
      ;
    }
  }

  /**
   * Parses a table DDL generated by public.tripal_get_table_ddl() SQL function.
   *
   * @param array $table_ddl
   *   Table definition as an array of lines.
   * @return array
   *   Parsed data in a hash.
   */
  protected function parse_table_ddl($table_ddl) {
    $table_definition = [
      'columns' => [],
      'constraints' => [],
      'indexes' => [],
    ];

    $i = 1;
    while (($i < count($table_ddl))
        && ($table_ddl[$i] != ');')
    ) {
      if (preg_match('/^  CONSTRAINT ([\w\$\x80-\xFF\.]+) (.+?),?$/', $table_ddl[$i], $match)) {
        // Constraint.
        $table_definition['constraints'][$match[1]] = $match[2];
      }
      elseif (
        preg_match(
          '/^  (\w+) (\w+.*?)( NOT NULL| NULL|)( DEFAULT .+?|),?$/',
          $table_ddl[$i],
          $match
        )
      ) {
        // Column.
        $table_definition['columns'][$match[1]] = [
          'type'    => $match[2],
          'null'    => $match[3],
          'default' => $match[4],
        ];
      }
      else {
        throw new \Exception('Failed to parse unexpected table definition line format for "' . $table_ddl[0] . '": "' . $table_ddl[$i] . '"');
      }
      ++$i;
    }

    // Parses indexes.
    if (++$i < count($table_ddl)) {
      while ($i < count($table_ddl)) {
        // Parse index name for later comparison.
        if (preg_match(
              '/
                ^\s*
                CREATE\s+
                (?:UNIQUE\s+)?INDEX\s+(?:CONCURRENTLY\s+)?
                (?:IF\s+NOT\s+EXISTS\s+)?
                # Capture index name.
                ([\w\$\x80-\xFF\.]+)\s+
                # Capture table column.
                ON\s+([\w\$\x80-\xFF\."]+)\s+
                # Capture index structure.
                USING\s+(.+);\s*
                $
              /ix',
              $table_ddl[$i],
              $match
            )
        ) {
          // Constraint.
          $table_definition['indexes'][$match[1]] = [
            'query' => $match[0],
            'name'  => $match[1],
            'table'  => $match[2],
            'using' => $match[3],
          ];
        }
        ++$i;
      }
    }

    return $table_definition;
  }

  /**
   * Upgrade schema tables.
   *
   * @param $chado_schema
   *   Name of the schema to upgrade.
   * @param $ref_chado_schema
   *   Name of the reference schema to use for upgrade.
   * @param $cleanup
   *   Remove tables not defined in the official Chado release.
   */
  protected function prepareUpgradeTables(
    $chado_schema,
    $ref_chado_schema,
    $cleanup
  ) {
    // Column-specific upgrade procedures. First level keys are table names,
    // second level keys are column names and values are array of 2 keys:
    // 'update' = a function to run to process update and return SQL queries
    // 'skip'   = an array of table name as keys and column names to skip as
    //            sub-keys. If no column names are specified, the whole table
    //            is skipped.
    $chado_column_upgrade = [
      /*
      Example:
      'analysis' => [
        'analysis_id' => [
          'update' => function ($chado_schema, $ref_chado_schema, $cleanup) {
            $sql_queries = [];
            $sql_queries[] = "ALTER $ref_chado_schema.analysis ALTER COLUMN analysis_id ...";
            $sql_queries[] = "CREATE TABLE $ref_chado_schema.analysis_cvterm ...";
            $sql_queries[] = "INSERT INTO $ref_chado_schema.analysis_cvterm ...";
            return $sql_queries;
          },
          'skip' => [
            'analysis' => [
              'analysis_id' => [],
            ],
            'analysis_cvterm' => [],
          ],
        ],
      ],
      */

    ];
    \Drupal::moduleHandler()->alter(
      ['tripal_chado_column_upgrade', 'tripal_chado_column_upgrade_1_13',],
      $chado_column_upgrade
    );

    $connection = $this->connection;
    // Here we don't use {} for tables as these are system tables.
    // Note: unlogged tables are not considered.
    $sql_query = "
      SELECT
        DISTINCT c.relname,
        c.relispartition,
        c.relkind,
        obj_description(c.oid) AS \"comment\"
      FROM
        pg_class c
        JOIN pg_namespace n ON (
          n.oid = c.relnamespace
          AND n.nspname = :schema
        )
      WHERE
        c.relkind IN ('r','p')
        AND c.relpersistence = 'p'
      ORDER BY c.relkind DESC, c.relname
    ";
    $old_tables = $connection
      ->query($sql_query, [':schema' => $chado_schema])
      ->fetchAllAssoc('relname')
    ;
    $new_tables = $connection
      ->query($sql_query, [':schema' => $ref_chado_schema])
      ->fetchAllAssoc('relname')
    ;
    $processed_new_tables = [];
    $new_table_definitions = [];
    $skip_table_column = [];

    // Check for existing tables with columns that can be updated.
    foreach ($old_tables as $old_table_name => $old_table) {
      if (!isset($this->upgradeQueries[$old_table_name])) {
        $this->upgradeQueries[$old_table_name] = [];
      }

      // Check lookup table for specific updates (column renaming, value
      // alteration, ...).
      if (array_key_exists($old_table_name, $chado_column_upgrade)) {
        // Get old table definition.
        $sql_query = "SELECT public.tripal_get_table_ddl('$chado_schema', '$new_table_name', TRUE) AS \"definition\";";
        $old_table_raw_definition = explode("\n", $connection->query($sql_query)->fetch()->definition);
        $old_table_definition = $this->parse_table_ddl($old_table_raw_definition);
        foreach ($old_table_definition['columns'] as $old_column => $old_column_def) {
          if (array_key_exists($old_column, $chado_column_upgrade[$old_table_name])) {
            // Init upgrade array.
            if (!isset($this->upgradeQueries[$old_table_name])) {
              $this->upgradeQueries[$old_table_name] = [];
            }
            // Get update queries.
            $this->upgradeQueries[$old_table_name][] =
              $chado_column_upgrade[$old_table_name][$old_column]['update'](
                $chado_schema,
                $ref_chado_schema,
                $cleanup
              );
            // Mark column as processed.
            $skip_table_column += $chado_column_upgrade[$old_table_name][$old_column]['skip'];
          }
        }
      }
    }

    // Check for missing or changed tables.
    foreach ($new_tables as $new_table_name => $new_table) {
      if (!isset($this->upgradeQueries[$new_table_name])) {
        $this->upgradeQueries[$new_table_name] = [];
      }

      // Get new table definition.
      $sql_query = "SELECT public.tripal_get_table_ddl('$ref_chado_schema', '$new_table_name', TRUE) AS \"definition\";";
      $new_table_raw_definition = explode("\n", $connection->query($sql_query)->fetch()->definition);
      $new_table_definition = $this->parse_table_ddl($new_table_raw_definition);



      // Check if table should be skipped.
      if (array_key_exists($new_table_name, $skip_table_column)
          && empty($skip_table_column[$new_table_name])) {
        continue;
      }

      if (array_key_exists($new_table_name, $old_tables)) {

        // Exists, compare.
        $old_table = $old_tables[$new_table_name];

        // Get old table definition.
        $sql_query = "SELECT public.tripal_get_table_ddl('$chado_schema', '$new_table_name', TRUE) AS \"definition\";";
        $old_table_raw_definition = explode("\n", $connection->query($sql_query)->fetch()->definition);
        $old_table_definition = $this->parse_table_ddl($old_table_raw_definition);

        $are_different = FALSE;

        // Start comparison.
        $alter_sql = [];
        // Compare columns.
        foreach ($new_table_definition['columns'] as $new_column => $new_column_def) {
          // Replace schema name if there.
          $new_default = str_replace(
            $this->refSchemaNameQuoted . '.',
            $this->schemaNameQuoted . '.',
            $new_table_definition['columns'][$new_column]['default']
          );
          $new_column_type = str_replace(
            $this->refSchemaNameQuoted . '.',
            $this->schemaNameQuoted . '.',
            $new_table_definition['columns'][$new_column]['type']
          );

          // Check if column exists in old table.
          if (array_key_exists($new_column, $old_table_definition['columns'])) {
            // Column exists, compare.
            // Data type.
            if ($old_table_definition['columns'][$new_column]['type'] != $new_table_definition['columns'][$new_column]['type']) {
              $alter_sql[] = "ALTER COLUMN $new_column TYPE $new_column_type";
            }
            // NULL option.
            if ($old_table_definition['columns'][$new_column]['null'] != $new_table_definition['columns'][$new_column]['null']) {
              if ($new_table_definition['columns'][$new_column]['null']) {
                $alter_sql[] = "ALTER COLUMN $new_column DROP NOT NULL";
              }
              else {
                $alter_sql[] = "ALTER COLUMN $new_column SET NOT NULL";
              }
            }
            // No DEFAULT value at the time (added later).
            if (!empty($old_table_definition['columns'][$new_column]['default'])) {
              $alter_sql[] = "ALTER COLUMN $new_column DROP DEFAULT";
            }
            // Remove processed column from old table data.
            unset($old_table_definition['columns'][$new_column]);
          }
          else {
            // Column does not exist, add.
            $alter_sql[] =
              "ADD COLUMN $new_column "
              . $new_column_type
              . $new_table_definition['columns'][$new_column]['null']
              . $new_default
            ;
          }
        }
        // Report old columns still there.
        if (!empty($old_table_definition['columns'])) {
          if ($cleanup) {
            foreach ($old_table_definition['columns'] as $old_column_name => $old_column) {
              $alter_sql[] = "DROP COLUMN $old_column_name";
            }
            \Drupal::messenger()->addStatus(
              t(
                "The following columns of table '%table' have been removed:\n%columns",
                [
                  '%columns' => implode(', ', array_keys($old_table_definition['columns'])),
                  '%table' => $new_table_name,
                ]
              )
            );
          }
          else {
            \Drupal::messenger()->addStatus(
              t(
                "The following columns of table '%table' should be removed manually if not used:\n%columns",
                [
                  '%columns' => implode(', ', array_keys($old_table_definition['columns'])),
                  '%table' => $new_table_name,
                ]
              )
            );
          }
        }

        // Remove all old constraints different from the new ones.
        foreach ($old_table_definition['constraints'] as $old_constraint_name => $old_constraint_def) {
          // if ((array_key_exists($old_constraint_name, $new_table_definition['constraints']))
          //     && (str_replace($this->refSchemaNameQuoted . '.', '', $new_table_definition['constraints'][$old_constraint_name] == str_replace($this->schemaNameQuoted . '.', '', $old_constraint_def)))
          // ) {
          //   // Unchanged constraint, remove it from def.
          //   unset($old_table_definition['constraints'][$old_constraint_name]);
          //   unset($new_table_definition['constraints'][$old_constraint_name]);
          // }
          // else {
            // Remove constraint.
            $alter_sql[] = "DROP CONSTRAINT IF EXISTS $old_constraint_name CASCADE";
          // }
        }

        // Alter table.
        if (!empty($alter_sql)) {
          $sql_query =
            "ALTER TABLE " . $this->schemaNameQuoted . ".$new_table_name\n  "
            . implode(",\n  ", $alter_sql)
          ;

          $this->upgradeQueries[$new_table_name][] = $sql_query;
          $processed_new_tables[] = $new_table_name;
        }

        // Process indexes.
        // Remove all old indexes.
        foreach ($old_table_definition['indexes'] as $old_index_name => $old_index_def) {
          $sql_query = "DROP INDEX IF EXISTS " . $this->schemaNameQuoted . ".$old_index_name CASCADE;";
          $this->upgradeQueries[$new_table_name][] = $sql_query;
        }

        // Create new indexes.
        foreach ($new_table_definition['indexes'] as $new_index_name => $new_index_def) {
          $index_def = str_replace($this->refSchemaNameQuoted . '.', $this->schemaNameQuoted . '.', $new_index_def['query']);
          $this->upgradeQueries[$new_table_name][] = $index_def;
        }

        // Save new definition.
        $new_table_definitions[$new_table_name] = $new_table_definition;

        // Processed: remove from $old_tables.
        unset($old_tables[$new_table_name]);
      }
      else {
        // Does not exist, add it.
        // We will add constraints when all tables are created/upgraded.
        $sql_query = "
          CREATE TABLE " . $this->schemaNameQuoted . ".$new_table_name
          (LIKE " . $this->refSchemaNameQuoted . ".$new_table_name EXCLUDING CONSTRAINTS)
        ;";
        $this->upgradeQueries[$new_table_name][] = $sql_query;
        $processed_new_tables[] = $new_table_name;

        // Drop column defaults for the moment (added later).
        $sql_query = "
          SELECT column_name
          FROM information_schema.columns
          WHERE
            table_schema = :schema
            AND table_name = :table_name
            AND column_default IS NOT NULL
        ";
        $result = $connection
          ->query($sql_query, [
            ':schema' => $chado_schema,
            ':table_name' => $new_table_name,
          ])
        ;
        if ($result) {
          while ($column = $result->fetch()) {
            $sql_query =
              'ALTER TABLE '
              . $this->schemaNameQuoted
              . '.'
              . $new_table_name
              . ' ALTER COLUMN '
              . $column->column_name
              . ' DROP DEFAULT'
            ;
            $this->upgradeQueries[$new_table_name][] = $sql_query;
          }
        }

        $new_table_definitions[$new_table_name] = $new_table_definition;
      }

      // Add comment.
      $sql_query = "COMMENT ON TABLE " . $this->schemaNameQuoted . ".$new_table_name IS " . pg_escape_literal($new_table->comment);
      $this->upgradeQueries[$new_table_name][] = $sql_query;
    }


    // Add table contraints.
    foreach ($new_tables as $new_table_name => $new_table) {

      // Check if table should be skipped.
      if (array_key_exists($new_table_name, $skip_table_column)
          && empty($skip_table_column[$new_table_name])) {
        continue;
      }

      $alter_first_sql = [];
      $alter_next_sql = [];
      $new_table_definition = $new_table_definitions[$new_table_name];
      foreach ($new_table_definition['constraints'] as $new_constraint_name => $new_constraint_def) {
        // Check if constraint should be skipped.
        if (array_key_exists($new_constraint_name, $new_table_definition['indexes'])) {
          continue;
        }
        $constraint_def = str_replace($this->refSchemaNameQuoted . '.', $this->schemaNameQuoted . '.', $new_constraint_def);
        $alter_first_sql[] = "DROP CONSTRAINT IF EXISTS $new_constraint_name";
        $alter_next_sql[] = "ADD CONSTRAINT $new_constraint_name $constraint_def";
      }

      // Alter table.
      if (!empty($alter_first_sql)) {
        $sql_query =
          "ALTER TABLE " . $this->schemaNameQuoted . ".$new_table_name\n  "
          . implode(",\n  ", $alter_first_sql)
        ;
        $this->upgradeQueries[$new_table_name][] = $sql_query;
      }
      if (!empty($alter_next_sql)) {
        $sql_query =
          "ALTER TABLE " . $this->schemaNameQuoted . ".$new_table_name\n  "
          . implode(",\n  ", $alter_next_sql)
        ;
        $this->upgradeQueries[$new_table_name][] = $sql_query;
      }
    }

    // Report table changes.
    if (!empty($old_tables)) {
      if ($cleanup) {
        foreach ($old_tables as $old_table_name => $old_table) {
          $sql_query =
            "DROP TABLE IF EXISTS "
            . $this->schemaNameQuoted
            . ".$old_table_name CASCADE;"
          ;
          $this->upgradeQueries['#cleanup'][] = $sql_query;
        }
        \Drupal::messenger()->addWarning(
          t(
            "The following tables have been removed:\n%tables",
            ['%tables' => implode(', ', array_keys($old_tables))]
          )
        );
      }
      else {
        \Drupal::messenger()->addWarning(
          t(
            "The following tables are not part of the new Chado schema specifications but have been left unchanged. If they are useless, they could be removed:\n%tables",
            ['%tables' => implode(', ', array_keys($old_tables))]
          )
        );
      }
    }
    if (!empty($new_tables)) {
      \Drupal::messenger()->addStatus(
        t(
          "The following schema tables were upgraded:\n%tables",
          ['%tables' => implode(', ', array_keys($new_tables))]
        )
      );
    }
    if (empty($old_tables) && empty($new_tables)) {
      \Drupal::messenger()->addStatus(t("All tables were already up-to-date."));
    }
  }

  /**
   * Upgrade table column defaults.
   *
   * @param $chado_schema
   *   Name of the schema to upgrade.
   * @param $ref_chado_schema
   *   Name of the reference schema to use for upgrade.
   * @param $cleanup
   *   Remove tables not defined in the official Chado release.
   */
  protected function prepareUpgradeTableDefauls(
    $chado_schema,
    $ref_chado_schema,
    $cleanup
  ) {
    $connection = $this->connection;
    // Here we don't use {} for tables as these are system tables.
    // Note: unlogged tables are not considered.
    $sql_query = "
      SELECT
        DISTINCT c.relname,
        c.relispartition,
        c.relkind,
        obj_description(c.oid) AS \"comment\"
      FROM
        pg_class c
        JOIN pg_namespace n ON (
          n.oid = c.relnamespace
          AND n.nspname = :schema
        )
      WHERE
        c.relkind IN ('r','p')
        AND c.relpersistence = 'p'
      ORDER BY c.relkind DESC, c.relname
    ";
    $new_tables = $connection
      ->query($sql_query, [':schema' => $ref_chado_schema])
      ->fetchAllAssoc('relname')
    ;

    // Process all tables.
    foreach ($new_tables as $new_table_name => $new_table) {
      // Get new table definition.
      $sql_query = "SELECT public.tripal_get_table_ddl('$ref_chado_schema', '$new_table_name', TRUE) AS \"definition\";";
      $new_table_raw_definition = explode("\n", $connection->query($sql_query)->fetch()->definition);
      $new_table_definition = $this->parse_table_ddl($new_table_raw_definition);

      foreach ($new_table_definition['columns'] as $new_column => $new_column_def) {
        // Replace schema name if there.
        $new_default = str_replace(
          $this->refSchemaNameQuoted . '.',
          $this->schemaNameQuoted . '.',
          $new_table_definition['columns'][$new_column]['default']
        );
        if ($new_default) {
          $sql_query =
            "ALTER TABLE "
            . $this->schemaNameQuoted
            . ".$new_table_name ALTER COLUMN $new_column SET " . $new_default;
          $this->upgradeQueries[$new_table_name . ' set default'] = [$sql_query];
        }
      }
    }
  }

  /**
   * Drop functions.
   *
   * @param $chado_schema
   *   Name of the schema to upgrade.
   * @param $ref_chado_schema
   *   Name of the reference schema to use for upgrade.
   * @param $cleanup
   *   Remove functions not defined in the official Chado release.
   */
  protected function prepareDropFunctions(
    $chado_schema,
    $ref_chado_schema,
    $cleanup
  ) {
    $connection = $this->connection;

    // Get the list of new functions.
    $sql_query = "
      SELECT
        p.proname AS \"proname\",
        replace(
          'DROP '
            || CASE
                WHEN p.prokind = 'a' THEN 'AGGREGATE'
                ELSE 'FUNCTION'
              END
            || ' IF EXISTS " . $this->schemaNameQuoted . ".'
            || quote_ident(p.proname)
            || '('
            ||  pg_get_function_identity_arguments(p.oid)
            || ') CASCADE',
          '" . $this->refSchemaNameQuoted . ".',
          '" . $this->schemaNameQuoted . ".'
        ) AS \"drop\"
      FROM pg_proc p
        JOIN pg_namespace n ON pronamespace = n.oid
      WHERE
        n.nspname = :ref_schema
        ORDER BY p.prokind ASC
      ;
    ";
    $proto_funcs = $connection
      ->query($sql_query, [
        ':ref_schema' => $ref_chado_schema,
      ])
      ->fetchAll()
    ;
    foreach ($proto_funcs as $proto_func) {
      $this->upgradeQueries['#drop_functions'][] = $proto_func->drop;
    }
  }

  /**
   * Upgrade functions.
   *
   * @param $chado_schema
   *   Name of the schema to upgrade.
   * @param $ref_chado_schema
   *   Name of the reference schema to use for upgrade.
   * @param $cleanup
   *   Remove functions not defined in the official Chado release.
   */
  protected function prepareFunctionUpgrade(
    $chado_schema,
    $ref_chado_schema,
    $cleanup
  ) {
    $connection = $this->connection;
    // Get the list of new functions.
    // Here we don't use {} for tables as these are system tables.
    $sql_query = "
        SELECT
          p.oid,
          p.proname,
          p.proname
            || '('
            ||  pg_get_function_identity_arguments(p.oid)
            || ')'
          AS \"proident\",
          regexp_replace(
            regexp_replace(
              pg_get_functiondef(p.oid),
              :regex_search,
              :regex_replace,
              'gis'
            ),
            '" . $this->refSchemaNameQuoted . "\\.',
            '" . $this->schemaNameQuoted . ".',
            'gis'
          ) AS \"def\"
          FROM pg_proc p
            JOIN pg_namespace n ON p.pronamespace = n.oid
        WHERE
          n.nspname = :ref_schema
          AND p.prokind != 'a'
        ;
    ";
    $funcs = $connection
      ->query($sql_query, [
        ':ref_schema' => $ref_chado_schema,
        ':regex_search' => '^\s*CREATE\s+FUNCTION',
        ':regex_replace' => 'CREATE OR REPLACE FUNCTION',
      ])
      ->fetchAll()
    ;

    // We use internal PG connection to create functions as function body
    // contains ';' which is forbiden in Drupal DB queries.
    $pgconnection = $this->getPgConnection();
    foreach ($funcs as $func) {
      // Update prototype.
      $object_id = $func->proident;
      if (!isset($this->upgradeQueries[$object_id])) {
        $this->upgradeQueries[$object_id] = [];
      }
      $this->upgradeQueries[$object_id][] = $func->def;
    }
    if ($cleanup) {
      // Get the list of functions not in the official Chado release.
      $sql_query = "
        SELECT
          regexp_replace(
            pg_get_functiondef(p.oid),
            :regex_search,
            :regex_replace,
            'gis'
          ) AS \"func\"
        FROM pg_proc p
          JOIN pg_namespace n ON p.pronamespace = n.oid
        WHERE
          n.nspname = :schema
          AND p.prokind != 'a'
          AND NOT EXISTS (
            SELECT TRUE
            FROM pg_proc pr
              JOIN pg_namespace nr ON pr.pronamespace = nr.oid
            WHERE
              nr.nspname = :ref_schema
              AND pr.prokind != 'a'
              AND pr.proname = p.proname
              AND regexp_replace(
                    pg_get_functiondef(pr.oid),
                    :regex_search,
                    :regex_replace,
                    'gis'
                  )
                = regexp_replace(
                    pg_get_functiondef(p.oid),
                    :regex_search,
                    :regex_replace,
                    'gis'
                  )
          );
      ";
      $old_funcs = $connection
        ->query(
          $sql_query,
          [
            ':ref_schema' => $ref_chado_schema,
            ':schema' => $chado_schema,
            // Extract the function name and its parameters (without the schema
            // name)
            ':regex_search' => '^\s*CREATE\s+(?:OR\s+REPLACE\s+)?FUNCTION\s+(?:[^\.\s]+\.)?([^\)]+\)).*$',
            ':regex_replace' => '\1',
          ]
        )
        ->fetchCol()
      ;
      foreach ($old_funcs as $old_func) {
        $sql_query =
          "DROP FUNCTION IF EXISTS "
          . $this->schemaNameQuoted
          . ".$old_func CASCADE;"
        ;
        $this->upgradeQueries['#cleanup'][] = $sql_query;
      }
      \Drupal::messenger()->addWarning(
        t(
          "The following functions have been removed:\n%functions",
          ['%functions' => implode(', ', $old_funcs)]
        )
      );
    }
  }

  /**
   * Upgrade aggregate functions.
   *
   * @param $chado_schema
   *   Name of the schema to upgrade.
   * @param $ref_chado_schema
   *   Name of the reference schema to use for upgrade.
   * @param $cleanup
   *   Remove aggregate functions not defined in the official Chado release.
   */
  protected function prepareAggregateFunctionUpgrade(
    $chado_schema,
    $ref_chado_schema,
    $cleanup
  ) {
    $connection = $this->connection;
    // Get the list of new aggregate functions.
    // Here we don't use {} for tables as these are system tables.
    $sql_query = "
      SELECT
        p.proname AS \"proname\",
        p.proname
          || '('
          ||  pg_get_function_identity_arguments(p.oid)
          || ')'
        AS \"proident\",
        'DROP AGGREGATE IF EXISTS "
      . $this->schemaNameQuoted
      . ".'
        || p.proname
        || '('
        || format_type(a.aggtranstype, NULL)
        || ')' AS \"drop\",
        'CREATE AGGREGATE "
      . $this->schemaNameQuoted
      . ".'
        || p.proname
        || '('
        || format_type(a.aggtranstype, NULL)
        || ') (sfunc = '
        || regexp_replace(a.aggtransfn::text, '(^|\\W)"
      . $this->refSchemaNameQuoted
      . "\\.', '\\1"
      . $this->schemaNameQuoted
      . ".', 'gis')
        || ', stype = '
        || format_type(a.aggtranstype, NULL)
        || CASE
             WHEN op.oprname IS NULL THEN ''
             ELSE ', sortop = ' || op.oprname
           END
        || CASE
             WHEN a.agginitval IS NULL THEN ''
             ELSE ', initcond = ''' || a.agginitval || ''''
           END
        || ')' AS \"def\"
      FROM
        pg_proc p
          JOIN pg_namespace n ON p.pronamespace = n.oid
          JOIN pg_aggregate a ON a.aggfnoid = p.oid
          LEFT JOIN pg_operator op ON op.oid = a.aggsortop
      WHERE
        n.nspname = :ref_schema
        AND p.prokind = 'a'
      ;
    ";
    $aggrfuncs = $connection
      ->query(
        $sql_query,
        [':ref_schema' => $ref_chado_schema]
      )
      ->fetchAll()
    ;
    // Keep track of official aggregate functions.
    $official_aggregate = [];
    // We use internal PG connection to create functions as function body
    // contains ';' which is forbiden in Drupal DB queries.
    $pgconnection = $this->getPgConnection();
    foreach ($aggrfuncs as $aggrfunc) {
      // Drop previous version and add a new one.
      $object_id = $aggrfunc->proident;
      if (!isset($this->upgradeQueries[$object_id])) {
        $this->upgradeQueries[$object_id] = [];
      }
      $this->upgradeQueries[$object_id][] = $aggrfunc->drop;
      $this->upgradeQueries[$object_id][] = $aggrfunc->def;
      $official_aggregate[$aggrfunc->drop] = TRUE;
    }

    // Cleanup if needed.
    if ($cleanup) {
      $sql_query = "
        SELECT
          'DROP AGGREGATE IF EXISTS " . $this->schemaNameQuoted . ".'
          || p.proname
          || '('
          || format_type(a.aggtranstype, NULL)
          || ')' AS \"drop\"
        FROM
          pg_proc p
            JOIN pg_namespace n ON p.pronamespace = n.oid
            JOIN pg_aggregate a ON a.aggfnoid = p.oid
            LEFT JOIN pg_operator op ON op.oid = a.aggsortop
        WHERE
          n.nspname = :schema
          AND p.prokind = 'a'
        ;
      ";
      $aggrfuncs = $connection
        ->query(
          $sql_query,
          [':schema' => $chado_schema]
        )
        ->fetchAll()
      ;
      // Drop aggregate functions not met in the reference schema.
      $dropped = [];
      foreach ($aggrfuncs as $aggrfunc) {
        if (!array_key_exists($aggrfunc->drop, $official_aggregate)) {
          $this->upgradeQueries['#cleanup'][] = $aggrfunc->drop;
          $dropped[] = preg_replace('/DROP AGGREGATE IF EXISTS ([^\)]+\))/', '\1', $aggrfunc->drop);
        }
      }
      if (!empty($dropped)) {
        \Drupal::messenger()->addWarning(
          t(
            "The following aggregate functions have been removed:\n%agg",
            ['%agg' => implode(', ', $dropped)]
          )
        );
      }
    }
  }

  /**
   * Upgrade views.
   *
   * @param $chado_schema
   *   Name of the schema to upgrade.
   * @param $ref_chado_schema
   *   Name of the reference schema to use for upgrade.
   * @param $cleanup
   *   Remove aggregate functions not defined in the official Chado release.
   */
  protected function prepareUpgradeViews(
    $chado_schema,
    $ref_chado_schema,
    $cleanup
  ) {
    $connection = $this->connection;
    // Get the list of new views.
    // Here we don't use {} for tables as these are system tables.
    $sql_query = "
      SELECT
        table_name,
        regexp_replace(
          view_definition::text,
          :regex_search,
          :regex_replace,
          'gis'
        ) AS \"def\"
      FROM information_schema.views
      WHERE table_schema = :ref_schema
      ;
    ";
    $views = $connection
      ->query($sql_query, [
        ':ref_schema' => $ref_chado_schema,
        ':regex_search' => '(^|\W)' . $this->refSchemaNameQuoted. '\.',
        ':regex_replace' => '\1' . $this->schemaNameQuoted . '.',
      ])
      ->fetchAll()
    ;
    foreach ($views as $view) {
      if (!isset($this->upgradeQueries[$view->table_name])) {
        $this->upgradeQueries[$view->table_name] = [];
      }
      $sql_query =
        'CREATE OR REPLACE VIEW '
        . $view->table_name
        . ' AS '
        . $view->def
      ;
      $this->upgradeQueries[$view->table_name][] = $sql_query;
      // Add comment if one.
      $sql_query = "
        SELECT obj_description(c.oid) AS \"comment\"
        FROM pg_class c,
          pg_namespace n
        WHERE n.nspname = :ref_schema
          AND c.relnamespace = n.oid
          AND c.relkind = 'v'
          AND c.relname = :view_name
        ;
      ";
      $comment = $connection
        ->query($sql_query, [
          ':ref_schema' => $ref_chado_schema,
          ':view_name' => $view->table_name,
        ])
      ;
      if ($comment && ($comment = $comment->fetch())) {
        $sql_query =
          "COMMENT ON VIEW "
          . $this->schemaNameQuoted
          . "."
          . $view->table_name
          . " IS " . pg_escape_literal($comment->comment)
        ;
        $this->upgradeQueries[$view->table_name][] = $sql_query;
      }
    }
  }

  /**
   * Associate sequences.
   *
   * @param $chado_schema
   *   Name of the schema to upgrade.
   * @param $ref_chado_schema
   *   Name of the reference schema to use for upgrade.
   */
  protected function prepareSequenceAssociation(
    $chado_schema,
    $ref_chado_schema
  ) {
    $connection = $this->connection;
    // Get the list of new sequences.
    // Here we don't use {} for tables as these are system tables.
    $sql_query = "
    SELECT sequence_name
      FROM information_schema.sequences
     WHERE sequence_schema = :ref_schema
      ;
    ";
    $sequences = $connection
      ->query($sql_query, [
        ':ref_schema' => $ref_chado_schema,
      ])->fetchCol()
    ;
    foreach ($sequences as $sequence) {
      $sql_query = "
        SELECT
          quote_ident(dc.relname) AS \"relname\",
          quote_ident(a.attname) AS \"attname\"
      FROM pg_class AS c
        JOIN pg_depend AS d ON (c.relfilenode = d.objid)
        JOIN pg_class AS dc ON (d.refobjid = dc.relfilenode)
        JOIN pg_attribute AS a ON (
          a.attnum = d.refobjsubid
          AND a.attrelid = d.refobjid
        )
        JOIN pg_namespace n ON c.relnamespace = n.oid
      WHERE n.nspname = :ref_schema
        AND c.relkind = 'S'
        AND c.relname = :sequence;
      ";
      $relation = $connection->query(
        $sql_query,
        [
          ':sequence' => $sequence,
          ':ref_schema' => $ref_chado_schema,
        ]
      );
      if ($relation && ($relation = $relation->fetch())) {
        $sql_query =
          'ALTER SEQUENCE '
          . $this->schemaNameQuoted
          . '.'
          . $sequence
          . ' OWNED BY '
          . $this->schemaNameQuoted
          . '.'
          . $relation->relname
          . '.'
          . $relation->attname
          . ';'
        ;
        // Array should have been initialized by table upgrade before.
        $this->upgradeQueries[$relation->relname][] = $sql_query;
      }
    }
  }

  /**
   * Upgrade comment.
   *
   * @param $chado_schema
   *   Name of the schema to upgrade.
   * @param $ref_chado_schema
   *   Name of the reference schema to use for upgrade.
   * @param $cleanup
   *   Remove aggregate functions not defined in the official Chado release.
   */
  protected function prepareCommentUpgrade(
    $chado_schema,
    $ref_chado_schema,
    $cleanup
  ) {
    $connection = $this->connection;
    // Find comment on columns.
    $sql_query = ";";
    //+TODO
    // $object_id = $new_type_name;
    // $this->upgradeQueries[$object_id] ?: [];
  }

  /**
   * Process upgrades.
   *
   * @param $chado_schema
   *   Name of the schema to upgrade.
   * @param $ref_chado_schema
   *   Name of the reference schema to use for upgrade.
   */
  protected function processUpgrades(
    $chado_schema,
    $filename
  ) {
    $pg_connection = $this->getPgConnection();
    $skip_objects = [];
    $fh = FALSE;
    if (isset($filename)) {
      if (file_exists($filename)) {
        throw new \Exception("Invalid file name '$filename'! File already exists!");
      }
      $fh = fopen($filename, 'w');
      if (!$fh) {
        throw new \Exception("Failed to open '$filename' for writting!");
      }
      fwrite($fh, "SET search_path = " . $this->schemaNameQuoted . ",public;\n");
    }
    
    foreach ($this->upgradeQueries as $object_id => $upgrade_queries) {
      // Skip #end elements that will be processed in the end.
      if ('#end' == $object_id) {
        continue;
      }
      // Process prioritized objects now (remove them from the regular queue and
      // add them to the priorities queue).
      if ('#priorities' == $object_id) {
        foreach ($this::CHADO_OBJECTS_PRIORITY as $priority) {
          if (array_key_exists($priority, $this->upgradeQueries)) {
            $this->upgradeQueries['#priorities'] = array_merge(
              $this->upgradeQueries['#priorities'],
              $this->upgradeQueries[$priority]
            );
          }
          else {
            throw new \Exception("Failed to prioritize object '$priority': object not found in schema definition!");
          }
          $skip_objects[$priority] = TRUE;
        }
        // Update current variable.
        $upgrade_queries = $this->upgradeQueries['#priorities'];
      }
      // Skip objects already processed (priorities).
      if (array_key_exists($object_id, $skip_objects)) {
        continue;
      }
      if ($fh) {
        foreach ($upgrade_queries as $sql_query) {
          fwrite($fh, $sql_query . "\n");
        }
      }
      else {
        foreach ($upgrade_queries as $sql_query) {
          $result = pg_query($pg_connection, $sql_query);
          if (!$result) {
            throw new \Exception('Upgrade query failed for query:\n$sql_query\nERROR: ' . pg_last_error());
          }
        }
      }
    }
    if ($fh) {
      foreach (array_reverse($this->upgradeQueries['#end']) as $sql_query ) {
        fwrite($fh, $sql_query . "\n");
      }
      fclose($fh);
    }
    else {
      foreach (array_reverse($this->upgradeQueries['#end']) as $sql_query ) {
        $result = pg_query($pg_connection, $sql_query);
        if (!$result) {
          throw new \Exception('Upgrade query failed: ' . pg_last_error());
        }
      }
    }

    // Clear queries.
    $this->upgradeQueries = [];
  }

  /**
   * Add missing initialization data.
   *
   * @param $chado_schema
   *   Name of the schema to upgrade.
   * @param $version
   *   Chado version to initialize.
   */
  protected function reinitSchema(
    $chado_schema,
    $version
  ) {
    $connection = $this->connection;
    // Get initialization script.
    $module_path = drupal_get_path('module', 'tripal_chado');
    $sql_file = $module_path . '/chado_schema/initialize-' . $version . '.sql';
    $sql = file_get_contents($sql_file);
    // Remove any search_path change containing 'chado' as a schema name.
    $sql = preg_replace(
      '/^(?:(?!\s*--)[^;]*;)*\s*SET\s*search_path\s*=\s*(?:[^;]+,|)chado(,[^;]+|)\s*;/i',
      '',
      $sql
    );
    $this->upgradeQueries['#init'] = [$sql];
    $this->upgradeQueries['#init'][] = "
      INSERT INTO "
      . $this->schemaNameQuoted
      . ".chadoprop (type_id, value, rank)
      VALUES (
        (
          SELECT cvterm_id
          FROM "
      . $this->schemaNameQuoted
      . ".cvterm CVT
            INNER JOIN "
      . $this->schemaNameQuoted
      . ".cv CV on CVT.cv_id = CV.cv_id
          WHERE CV.name = 'chado_properties' AND CVT.name = 'version'
        ),
        '$version',
        0
      ) ON CONFLICT (type_id, rank) DO UPDATE SET value = '$version';
    ";
  }

  /**
   * Return table dependencies.
   *
   * @param $chado_schema
   *   Name of the schema to process.
   *
   * @return array
   *   first-level keys are table name, second level keys are column names,
   *   third level keys are foreign table names and values are foreign column
   *   names.
   */
  protected function getTableDependencies(
    $chado_schema
  ) {
    $connection = $this->connection;
    // Here we don't use {} for tables as these are system tables.
    // Note: unlogged tables are not considered.
    $sql_query = "
      SELECT
        DISTINCT c.relname
      FROM
        pg_class c
        JOIN pg_namespace n ON (
          n.oid = c.relnamespace
          AND n.nspname = :schema
        )
      WHERE
        c.relkind IN ('r','p')
        AND c.relpersistence = 'p'
      ORDER BY c.relkind DESC, c.relname
    ";
    $tables = $connection
      ->query($sql_query, [':schema' => $chado_schema])
      ->fetchAllAssoc('relname')
    ;

    $table_dependencies = [];
    // Process all tables.
    foreach ($tables as $table_name => $table) {
      $table_dependencies[$table_name] = [];

      // Get new table definition.
      $sql_query = "SELECT public.tripal_get_table_ddl('$chado_schema', '$table_name', TRUE) AS \"definition\";";
      $table_raw_definition = explode("\n", $connection->query($sql_query)->fetch()->definition);
      $table_definition = $this->parse_table_ddl($table_raw_definition);

      // Process FK constraints.
      $foreign_keys = [];
      foreach ($table_definition['constraints'] as $constraint_name => $constraint_def) {
        if (preg_match(
              '/
                # Match "FOREIGN KEY ("
                FOREIGN\s+KEY\s*\(
                   # Capture current table columns (one or more).
                  (
                    (?:[\w\$\x80-\xFF\.]+\s*,?\s*)+
                  )
                \)\s*
                # Match "REFERENCES"
                REFERENCES\s*
                  # Caputre evental schema name.
                  ([\w\$\x80-\xFF]+\.|)
                  # Caputre foreign table name.
                  ([\w\$\x80-\xFF]+)\s*
                  \(
                    # Capture foreign table columns (one or more).
                    (
                      (?:[\w\$\x80-\xFF]+\s*,?\s*)+
                    )
                  \)
              /ix',
              $constraint_def,
              $match
            )
        ) {
          $table_columns =  preg_split('/\s*,\s*/', $match[1]);
          $foreign_table_schema = $match[2];
          $foreign_table = $match[3];
          $foreign_table_columns =  preg_split('/\s*,\s*/', $match[4]);
          if (count($table_columns) != count($foreign_table_columns)) {
            throw new \Exception("Failed to parse foreign key definition for table '$table_name':\n'$constraint_def'");
          }
          else {
            for ($i = 0; $i < count($table_columns); ++$i) {
              $table_dependencies[$table_name][$table_columns[$i]] = [
                $foreign_table => $foreign_table_columns[$i],
              ];
            }
          }
        }
      }
    }
    return $table_dependencies;
  }

}

/**
 * Hook to alter tripal_chado_column_upgrade variable.
 *
 * @see prepareUpgradeTables()
 */
function hook_tripal_chado_column_upgrade_alter(&$chado_column_upgrade) {
  $chado_column_upgrade = array_merge(
    $chado_column_upgrade,
    [
      'analysis' => [
        'analysis_id' => [
          'update' => function ($chado_schema, $ref_chado_schema, $cleanup) {
            $sql_queries = [];
            $sql_queries[] = "ALTER $ref_chado_schema.analysis ALTER COLUMN analysis_id ...";
            $sql_queries[] = "CREATE TABLE $ref_chado_schema.analysis_cvterm ...";
            $sql_queries[] = "INSERT INTO $ref_chado_schema.analysis_cvterm ...";
            return $sql_queries;
          },
          'skip' => [
            'analysis' => [
              'analysis_id' => [],
            ],
            'analysis_cvterm' => [],
          ],
        ],
      ],
    ]
  );
}
