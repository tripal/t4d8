<?php

namespace Drupal\tripal_chado\Services;

use Drupal\Core\Database\Database;
use Drupal\tripal\Services\bulkPgSchemaInstaller;

class chadoUpgrader extends bulkPgSchemaInstaller {

  /**
   * Name of the reference schema.
   *
   * This name can be overriden by extending classes.
   */
  public const CHADO_REF_SCHEMA_13 = '_chado_13_template';

  /**
   * Defines a priority order to process some Chado objects to upgrade.
   */
  public const CHADO_OBJECT_PRIORITY_13 = [
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
  ];

  /**
   * PostgreSQL-quoted schema name.
   */
  protected $schemaNameQuoted;

  /**
   * The reference schema name.
   */
  protected $refSchemaName;

  /**
   * PostgreSQL-quoted reference schema name.
   */
  protected $refSchemaNameQuoted;

  /**
   * Upgrade SQL queries.
   */
  protected $upgradeQueries;

  /**
   * Upgrade Chado schema to the specified version.
   *
   * First, we create a new Chado template schema (see CHADO_REF_SCHEMA*) to use
   * as a reference for the upgrade process. Then, we process each PostgreSQL
   * object categories and compare the schema to upgrade to the reference one.
   * When changes are required, we store the corresponding SQL queries for each
   * object in the 'upgradeQueries' class member. Cleanup queries are stored in
   * 'upgradeQueries['#cleanup']' in order to remove unnecessary objects.
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
   * 16) Update Tripal integration
   *
   * Note: a couple of PostgreSQL object are not processed as they are not part
   * of Chado schema specifications: collations, domains, triggers, unlogged
   * tables and materialized views (in PostgreSQL sens, Tripal MV could be
   * processed but are removed by default and will need to be recreated).
   *
   * Note: in most queries, we don't use "{}" for tables as these are often
   * system tables (pg_catalog.pg_* or information_schema tables). Since Drupal
   * uses "{}" to prefix tables in queries, we don't want that for system
   * tables.
   *
   * @param string $chado_schema
   *   The schema name (unquoted) of the existing Chado schema to upgrade.
   * @param string $version
   *   The version of chado you would like to upgrade to.
   * @param boolean $cleanup
   *   If TRUE, also remove any stuff not in Chado 1.3 schema definition.
   * @param string $filename
   *   If specified, upgrade queries will be stored in the given file instead of
   *   being executed.
   */
  public function upgrade(
    $chado_schema,
    $version,
    $cleanup = TRUE,
    $filename = NULL
  ) {

    // Check parameter.
    if (empty($chado_schema)) {
      throw new \Exception('No schema name provided! Nothing to upgrade.');
    }
    if (empty($version)) {
      throw new \Exception(
        'No new schema version provided! Unable to upgrade.'
      );
    }

    // Check if the schema to upgrade exists.
    if (!$this->checkSchema($chado_schema)) {
      throw new \Exception(
        'The schema to upgrade "'
        . $chado_schema
        . '" does not exist. Please select an existing schema to upgrade.'
      );
    }
    
    // Check the version is valid.
    if (empty($version)) {
      $version = '1.3';
    }
    if (!in_array($version, ['1.3'])) {
      throw new \Exception("That version ($version) is not supported by the upgrader.");
    }
    $priorities = [];
    // Setup DB object upgrade priority according to Chado version.
    switch ($version) {
      case '1.3':
        $priorities = $this::CHADO_OBJECT_PRIORITY_13;
        break;
    }

    if (!empty($filename)) {
      if (file_exists($filename)) {
        throw new \Exception("Invalid file name '$filename'! File already exists!");
      }
    }

    $connection = $this->connection;

    // Save previous search_path.
    $sql_query = "SELECT setting FROM pg_settings WHERE name = 'search_path';";
    $old_search_path = $connection->query($sql_query)->fetch()->setting ?: "''";

    // Get PostgreSQL quoted name for SQL queries (nb.: the schema name may be
    // returned unquoted if it is not necessary).
    $sql_query = "SELECT quote_ident(:schema) AS \"qi\";";
    $chado_schema_quoted = $connection
      ->query($sql_query, [':schema' => $chado_schema])
      ->fetch()
      ->qi ?: $chado_schema
    ;

    $ref_chado_schema = $this::CHADO_REF_SCHEMA_13;
    $ref_chado_schema_quoted = $connection
      ->query($sql_query, [':schema' => $ref_chado_schema])
      ->fetch()
      ->qi ?: $ref_chado_schema
    ;

    // Make sure the reference schema is available.
    $this->setupReferenceSchema($version, $ref_chado_schema);

    // Init query array. We initialize a list of element to have them processed
    // in correct order.
    $this->upgradeQueries = [
      '#start'                => ['START TRANSACTION;'],
      '#cleanup'              => [],
      '#drop_column_defaults' => [],
      '#drop_functions'       => [],
      '#drop_views'           => [],
      '#types'                => [],
      '#sequences'            => [],
      '#priorities'           => [],
      // "#end" will be processed at last even if new elements are added after
      // to upgradeQueries, and its queries will be processed in reverse order.
      '#end'                  => ['COMMIT;'],
    ];

    try {
      // Make sure we work on the given schema (both Drupal and PG connections).
      // We keep drupal schema as we call some Drupal functions that may need
      // database access.
      $drupal_schema = chado_get_schema_name('drupal');
      $sql_query = "SET search_path = $chado_schema_quoted,$drupal_schema;";
      $connection->query($sql_query);
      pg_query($this->getPgConnection(), $sql_query);
      $this->upgradeQueries['#start'][] = $sql_query;
      $this->upgradeQueries['#end'][] = "SET search_path = $old_search_path;";

      $parameters = [
        'chado_schema'            => $chado_schema,
        'chado_schema_quoted'     => $chado_schema_quoted,
        'ref_chado_schema'        => $ref_chado_schema,
        'ref_chado_schema_quoted' => $ref_chado_schema_quoted,
        'cleanup'                 => $cleanup,
        'version'                 => $version,
      ];

      // Compare schema structures...
      // - Remove column defaults.
      $this->prepareDropColumnDefaults($parameters);

      // - Remove functions.
      $this->prepareDropFunctions($parameters);

      // - Drop old views to remove dependencies on tables.
      $this->prepareDropAllViews($parameters);

      // - Check types.
      $this->prepareUpgradeTypes($parameters);

      // - Upgrade existing sequences and add missing ones.
      $this->prepareUpgradeSequences($parameters);

      // - Create prototype functions.
      $this->preparePrototypeFunctions($parameters);

      // - Tables.
      $this->prepareUpgradeTables($parameters);

      // - Sequence associations.
      $this->prepareSequenceAssociation($parameters);

      // - Views.
      $this->prepareUpgradeViews($parameters);

      // - Upgrade functions (fill function bodies).
      $this->prepareFunctionUpgrade($parameters);

      // - Upgrade aggregate functions.
      $this->prepareAggregateFunctionUpgrade($parameters);

      // - Tables defaults.
      $this->prepareUpgradeTableDefauls($parameters);

      // - Upgrade comments.
      $this->prepareCommentUpgrade($parameters);

      // - Add missing initialization data.
      $this->reinitSchema($parameters);

      // - Process upgrades.
      $this->processUpgradeQueries($priorities, $filename);

      // If schema is integrated into Tripal, update version.
      $this->connection->update('chado_installations')
        ->fields([
          'version' => $version,
          'created' => \Drupal::time()->getRequestTime(),
          'updated' => \Drupal::time()->getRequestTime(),
        ])
        ->condition('schema_name', $chado_schema, '=')
        ->execute()
      ;
      // @TODO: Test transaction behavior.
    }
    catch (Exception $e) {
      pg_query($this->getPgConnection(), 'ROLLBACK;');

      // Restore search_path.
      $sql_query = "SET search_path = $old_search_path;";
      $connection->query($sql_query);
      pg_query($this->getPgConnection(), $sql_query);

      // Rethrow exception.
      throw $e;
    }

    // Restore search_path.
    $sql_query = "SET search_path = $old_search_path;";
    $connection->query($sql_query);
    pg_query($this->getPgConnection(), $sql_query);
  }

  /**
   * Setups the refrence schema.
   *
   * @param $version
   *   Schema version to use for upgrade. Default to '1.3'.
   * @param $ref_chado_schema
   *   Name of the reference schema to use for upgrade. If not set,
   *   $this::CHADO_REF_SCHEMA_13 class instance constant will be used.
   */
  protected function setupReferenceSchema(
    $version = '1.3',
    $ref_chado_schema = NULL
  ) {
    $connection = $this->connection;

    // Use default schema name if no name was specified.
    if (empty($ref_chado_schema)) {
      // The ref schema name can be overriden by extending classes.
      $ref_chado_schema = $this::CHADO_REF_SCHEMA_13;
    }

    // Check if the schema alread exists.
    if ($this->checkSchema($ref_chado_schema)) {
      // Yes, check minimal structure.
      $schema =
        new \Drupal\tripal_chado\api\ChadoSchema(NULL, $ref_chado_schema);
      if (!$schema->checkTableExists('chadoprop')) {
        throw new \Exception('Reference schema does not contain chadoprop table. It seems it is not a complete >=1.3 Chado schema and it should be removed.');
      }
    }
    else {
      // No, create a new reference schema.
      $this->createSchema($ref_chado_schema);

      $sql_query = "SELECT quote_ident(:schema) AS \"qi\";";
      $ref_chado_schema_quoted = $connection
        ->query($sql_query, [':schema' => $ref_chado_schema])
        ->fetch()
        ->qi ?: $ref_chado_schema
      ;


      // Apply SQL file containing schema definitions.
      $module_path = drupal_get_path('module', 'tripal_chado');
      $file_path =
        $module_path
        . '/chado_schema/chado-only-'
        . $version
        . '.sql'
      ;

      // Run SQL file defining Chado schema.
      $success = $this->applySQL($file_path, $ref_chado_schema);
      if ($success) {
        // Initialize schema with minimal data.
        $file_path =
          $module_path
          . '/chado_schema/initialize-'
          . $version
          . '.sql'
        ;
        $success = $this->applySQL($file_path, $ref_chado_schema);
      }
      if (!$success) {
        // Failed to instanciate ref schema. Drop any partial ref schema.
        try {
          $this->dropSchema($ref_chado_schema);
        }
        catch (Exception $e) {
          // Warn error in logs.
          $this->logger->error(
            'Failed to drop incomplete reference schema "'
            . $ref_chado_schema
            . '": '
            . $e->getMessage()
          );
        }
        throw new \Exception(
          'Reference schema "'
          . $ref_chado_schema
          . '" for update could not be initialized.'
        );
      }

      // Add version so the UI will detect the correct version of the reference
      // schema.
      $sql_query = "
        INSERT INTO "
        . $ref_chado_schema_quoted
        . ".chadoprop (type_id, value)
        VALUES (
          (
            SELECT cvterm_id
            FROM "
        . $ref_chado_schema_quoted
        . ".cvterm CVT
              INNER JOIN "
        . $ref_chado_schema_quoted
        . ".cv CV on CVT.cv_id = CV.cv_id
            WHERE CV.name = 'chado_properties' AND CVT.name = 'version'
          ),
          :version
        );
      ";
      $this->connection->query($sql_query, [':version' => $version]);
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
  public function parseTableDdl($table_ddl) {
    $table_definition = [
      'columns' => [],
      'constraints' => [],
      'indexes' => [],
    ];

    // Skip "CREATE TABLE" line.
    $i = 1;
    // Loop until end of table definition.
    while (($i < count($table_ddl))
        && (!preg_match('/^\s*\)\s*;\s*$/', $table_ddl[$i]))
    ) {
      if (empty($table_ddl[$i])) {
        ++$i;
        continue;
      }
      if (
          preg_match(
            '/^\s*CONSTRAINT\s*([\w\$\x80-\xFF\.]+)\s+(.+?),?\s*$/',
            $table_ddl[$i],
            $match
          )
      ) {
        // Constraint.
        $table_definition['constraints'][$match[1]] = $match[2];
      }
      elseif (
        preg_match(
          '/^\s*(\w+)\s+(\w+.*?)(\s+NOT\s+NULL|\s+NULL|)(\s+DEFAULT\s+.+?|),?\s*$/',
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
        // If it happens, it means the tripal_get_table_ddl() SQL function
        // changed and this script should be adapted.
        throw new \Exception(
          'Failed to parse unexpected table definition line format for "'
          . $table_ddl[0]
          . '": "'
          . $table_ddl[$i]
          . '"'
        );
      }
      ++$i;
    }

    // Parses indexes.
    if (++$i < count($table_ddl)) {
      while ($i < count($table_ddl)) {
        if (empty($table_ddl[$i])) {
          ++$i;
          continue;
        }
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
        else {
          // If it happens, it means the tripal_get_table_ddl() SQL function
          // changed and this script should be adapted.
          throw new \Exception(
            'Failed to parse unexpected table DDL line format for "'
            . $table_ddl[0]
            . '": "'
            . $table_ddl[$i]
            . '"'
          );
        }
        ++$i;
      }
    }
    return $table_definition;
  }

  /**
   * Remove table column defaults.
   *
   * Since column defaults may use functions that need to be upgraded, we remove
   * those default in order to drop old functions without removing column
   * content.
   *
   * @param array $parameters
   *   Upgrade parameters (chado_schema, ref_chado_schema, cleanup, version)
   */
  protected function prepareDropColumnDefaults($parameters) {
    $connection              = $this->connection;
    $chado_schema            = $parameters['chado_schema'];
    $chado_schema_quoted     = $parameters['chado_schema_quoted'];
    $ref_chado_schema        = $parameters['ref_chado_schema'];
    $ref_chado_schema_quoted = $parameters['ref_chado_schema_quoted'];

    // Get tables.
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
      $table_definition = $this->parseTableDdl($table_raw_definition);
      foreach ($table_definition['columns'] as $column => $column_def) {
        if (!empty($column_def['default'])) {
          $this->upgradeQueries['#drop_column_defaults'][] =
            "ALTER TABLE "
            . $chado_schema_quoted
            . '.'
            . $table
            . " ALTER COLUMN $column DROP DEFAULT;"
          ;
        }
      }
    }
  }

  /**
   * Drop functions.
   *
   * @param array $parameters
   *   Upgrade parameters (chado_schema, ref_chado_schema, cleanup, version)
   */
  protected function prepareDropFunctions($parameters) {
    $connection              = $this->connection;
    $chado_schema_quoted     = $parameters['chado_schema_quoted'];
    $ref_chado_schema        = $parameters['ref_chado_schema'];
    $ref_chado_schema_quoted = $parameters['ref_chado_schema_quoted'];

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
            || ' IF EXISTS " . $chado_schema_quoted . ".'
            || quote_ident(p.proname)
            || '('
            ||  pg_get_function_identity_arguments(p.oid)
            || ') CASCADE',
          '" . $ref_chado_schema_quoted . ".',
          '" . $chado_schema_quoted . ".'
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
      $this->upgradeQueries['#drop_functions'][] = $proto_func->drop . ';';
    }
  }

  /**
   * Drop all views of schema to upgrade.
   *
   * @param array $parameters
   *   Upgrade parameters (chado_schema, ref_chado_schema, cleanup, version)
   */
  protected function prepareDropAllViews($parameters) {
    $connection              = $this->connection;
    $chado_schema            = $parameters['chado_schema'];
    $chado_schema_quoted     = $parameters['chado_schema_quoted'];

    // Get views.
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
        . $chado_schema_quoted
        . ".$view CASCADE;"
      ;
    }
  }

  /**
   * Upgrade schema types.
   *
   * @param array $parameters
   *   Upgrade parameters (chado_schema, ref_chado_schema, cleanup, version)
   */
  protected function prepareUpgradeTypes($parameters) {
    $connection              = $this->connection;
    $chado_schema            = $parameters['chado_schema'];
    $chado_schema_quoted     = $parameters['chado_schema_quoted'];
    $ref_chado_schema        = $parameters['ref_chado_schema'];
    $cleanup                 = $parameters['cleanup'];

    // Get database types.
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
            . $chado_schema_quoted
            . ".$new_type_name CASCADE;";
          $this->upgradeQueries['#types'][] =
            "CREATE TYPE "
            . $chado_schema_quoted
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
          . $chado_schema_quoted
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
            . $chado_schema_quoted
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
   * Upgrade schema sequences.
   *
   * @param array $parameters
   *   Upgrade parameters (chado_schema, ref_chado_schema, cleanup, version)
   */
  protected function prepareUpgradeSequences($parameters) {
    $connection              = $this->connection;
    $chado_schema            = $parameters['chado_schema'];
    $chado_schema_quoted     = $parameters['chado_schema_quoted'];
    $ref_chado_schema        = $parameters['ref_chado_schema'];
    $cleanup                 = $parameters['cleanup'];

    // Get sequences.
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
        . $chado_schema_quoted
        . '.'
        . $new_seq_name
        . $increment_sql
        . $min_val_sql
        . $max_val_sql
        . $start_sql
        . $cycle_sql
        . ';'
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
            . $chado_schema_quoted
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
   * Create prototype functions.
   *
   * Replace existing functions with same signature by protoype functions.
   * Prototype functions are functions with an empty body. Those functions will
   * be filled later with the upgraded content. The idea here is to be able to
   * link those functions in other database objects without having to deal with
   * function inter-dependencies (i.e. empty body, so no dependency inside) and
   * keep the same function reference when it will be upgraded.
   *
   * @param array $parameters
   *   Upgrade parameters (chado_schema, ref_chado_schema, cleanup, version)
   */
  protected function preparePrototypeFunctions($parameters) {
    $connection              = $this->connection;
    $chado_schema_quoted     = $parameters['chado_schema_quoted'];
    $ref_chado_schema        = $parameters['ref_chado_schema'];
    $ref_chado_schema_quoted = $parameters['ref_chado_schema_quoted'];

    $sql_query = "
      SELECT
        p.proname AS \"proname\",
        p.proname
          || '('
          ||  pg_get_function_identity_arguments(p.oid)
          || ')'
        AS \"proident\",
        replace(
          'CREATE OR REPLACE FUNCTION " . $chado_schema_quoted . ".'
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
          '" . $ref_chado_schema_quoted . ".',
          '" . $chado_schema_quoted . ".'
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
   * Upgrade schema tables.
   *
   * @param array $parameters
   *   Upgrade parameters (chado_schema, ref_chado_schema, cleanup, version)
   */
  protected function prepareUpgradeTables($parameters) {
    $connection              = $this->connection;
    $chado_schema            = $parameters['chado_schema'];
    $chado_schema_quoted     = $parameters['chado_schema_quoted'];
    $ref_chado_schema        = $parameters['ref_chado_schema'];
    $ref_chado_schema_quoted = $parameters['ref_chado_schema_quoted'];
    $cleanup                 = $parameters['cleanup'];

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
            $sql_queries[] =
              "ALTER $ref_chado_schema.analysis ALTER COLUMN analysis_id ...";
            $sql_queries[] =
              "CREATE TABLE $ref_chado_schema.analysis_cvterm ...";
            $sql_queries[] =
              "INSERT INTO $ref_chado_schema.analysis_cvterm ...";
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
    // Allow Tripal (custom) extensions to alter table upgrade.
    \Drupal::moduleHandler()->alter(
      ['tripal_chado_column_upgrade', 'tripal_chado_column_upgrade_1_13',],
      $chado_column_upgrade
    );

    // Get tables.
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

    // Check for existing tables with columns that can be updated through
    // specific functions (@see hook_tripal_chado_column_upgrade_alter()).
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
        $old_table_definition = $this->parseTableDdl($old_table_raw_definition);
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
    // First loop adds missing tables, upgrade columns on existing table,
    // removes column defaults, all constraints and indexes.
    foreach ($new_tables as $new_table_name => $new_table) {
      if (!isset($this->upgradeQueries[$new_table_name])) {
        $this->upgradeQueries[$new_table_name] = [];
      }

      // Get new table definition.
      $sql_query = "SELECT public.tripal_get_table_ddl('$ref_chado_schema', '$new_table_name', TRUE) AS \"definition\";";
      $new_table_raw_definition = explode("\n", $connection->query($sql_query)->fetch()->definition);
      $new_table_definition = $this->parseTableDdl($new_table_raw_definition);

      // Check if table should be skipped.
      if (array_key_exists($new_table_name, $skip_table_column)
          && empty($skip_table_column[$new_table_name])) {
        continue;
      }

      if (array_key_exists($new_table_name, $old_tables)) {

        // New table exists in old schema, compare.
        $old_table = $old_tables[$new_table_name];

        // Get old table definition.
        $sql_query = "SELECT public.tripal_get_table_ddl('$chado_schema', '$new_table_name', TRUE) AS \"definition\";";
        $old_table_raw_definition = explode(
          "\n",
          $connection->query($sql_query)->fetch()->definition
        );
        $old_table_definition = $this->parseTableDdl($old_table_raw_definition);

        $are_different = FALSE;

        // Start comparison.
        $alter_sql = [];
        // Compare columns.
        foreach (
          $new_table_definition['columns'] as $new_column => $new_column_def
        ) {
          // Replace schema name if there is one.
          $new_default = str_replace(
            $ref_chado_schema_quoted . '.',
            $chado_schema_quoted . '.',
            $new_table_definition['columns'][$new_column]['default']
          );
          $new_column_type = str_replace(
            $ref_chado_schema_quoted . '.',
            $chado_schema_quoted . '.',
            $new_table_definition['columns'][$new_column]['type']
          );

          // Check if column exists in old table.
          if (array_key_exists($new_column, $old_table_definition['columns'])) {
            // Column exists, compare.
            // Data type.
            $old_type = $old_table_definition['columns'][$new_column]['type'];
            $new_type = $new_table_definition['columns'][$new_column]['type'];
            if ($old_type != $new_type) {
              $alter_sql[] = "ALTER COLUMN $new_column TYPE $new_column_type";
            }
            // NULL option.
            $old_null = $old_table_definition['columns'][$new_column]['null'];
            $new_null = $new_table_definition['columns'][$new_column]['null'];
            if ($old_null != $new_null) {
              if ($new_table_definition['columns'][$new_column]['null']) {
                $alter_sql[] = "ALTER COLUMN $new_column DROP NOT NULL";
              }
              else {
                $alter_sql[] = "ALTER COLUMN $new_column SET NOT NULL";
              }
            }
            // No DEFAULT value at the time (added later).
            if (
              !empty($old_table_definition['columns'][$new_column]['default'])
            ) {
              $alter_sql[] = "ALTER COLUMN $new_column DROP DEFAULT";
            }
            // Remove processed column from old table data.
            unset($old_table_definition['columns'][$new_column]);
          }
          else {
            // Column does not exist, add (without default as it will be added
            // later).
            $alter_sql[] =
              "ADD COLUMN $new_column "
              . $new_column_type
              . $new_table_definition['columns'][$new_column]['null']
            ;
          }
        }
        // Report old columns still there.
        if (!empty($old_table_definition['columns'])) {
          $old_col_def = $old_table_definition['columns'];
          if ($cleanup) {
            foreach ($old_col_def as $old_column_name => $old_column) {
              $alter_sql[] = "DROP COLUMN $old_column_name";
            }
            \Drupal::messenger()->addStatus(
              t(
                "The following columns of table '%table' have been removed:\n%columns",
                [
                  '%columns' => implode(', ', array_keys($old_col_def)),
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
                  '%columns' => implode(', ', array_keys($old_col_def)),
                  '%table' => $new_table_name,
                ]
              )
            );
          }
        }

        // Remove all constraints.
        $old_cstr_def = $old_table_definition['constraints'];
        foreach ($old_cstr_def as $old_constraint_name => $old_constraint_def) {
          $alter_sql[] =
            "DROP CONSTRAINT IF EXISTS $old_constraint_name CASCADE";
        }

        // Alter table.
        if (!empty($alter_sql)) {
          $sql_query =
            "ALTER TABLE " . $chado_schema_quoted . ".$new_table_name\n  "
            . implode(",\n  ", $alter_sql)
            . ';'
          ;

          $this->upgradeQueries[$new_table_name][] = $sql_query;
          $processed_new_tables[] = $new_table_name;
        }

        // Remove all old indexes.
        foreach (
          $old_table_definition['indexes'] as $old_index_name => $old_index_def
        ) {
          $sql_query =
            "DROP INDEX IF EXISTS "
            . $chado_schema_quoted
            . ".$old_index_name;"
          ;
          $this->upgradeQueries[$new_table_name][] = $sql_query;
        }

        // Saves table definition.
        $new_table_definitions[$new_table_name] = $new_table_definition;

        // Processed: remove from $old_tables for change report.
        unset($old_tables[$new_table_name]);
      }
      else {
        // Does not exist, add it.
        $sql_query =
          "CREATE TABLE "
          . $chado_schema_quoted
          . ".$new_table_name (LIKE "
          . $ref_chado_schema_quoted
          . ".$new_table_name EXCLUDING DEFAULTS EXCLUDING CONSTRAINTS EXCLUDING INDEXES INCLUDING COMMENTS);"
        ;
        $this->upgradeQueries[$new_table_name][] = $sql_query;
        $processed_new_tables[] = $new_table_name;

        // Saves table definition.
        $new_table_definitions[$new_table_name] = $new_table_definition;
      }

      // Add comment.
      $sql_query =
        "COMMENT ON TABLE "
        . $chado_schema_quoted
        . ".$new_table_name IS "
        . pg_escape_literal($new_table->comment)
        . ';'
      ;
      $this->upgradeQueries[$new_table_name][] = $sql_query;
    }

    // Second loop adds indexes and table contraints without foreign keys.
    foreach ($new_tables as $new_table_name => $new_table) {
      // Check if table should be skipped.
      if (array_key_exists($new_table_name, $skip_table_column)
          && empty($skip_table_column[$new_table_name])) {
        continue;
      }

      $upgq_id = $new_table_name . ' 2nd pass';
      if (!isset($this->upgradeQueries[$upgq_id])) {
        $this->upgradeQueries[$upgq_id] = [];
      }

      $alter_sql = [];
      $new_table_def = $new_table_definitions[$new_table_name];
      $new_cstr_def = $new_table_def['constraints'];
      $index_to_skip = [];

      foreach ($new_cstr_def as $new_constraint_name => $new_constraint_def) {
        // Skip foreign keys for now.
        if (!preg_match('/(?:^|\s)FOREIGN\s+KEY(?:\s|$)/', $new_constraint_def)) {
          $constraint_def = str_replace(
            $ref_chado_schema_quoted . '.',
            $chado_schema_quoted . '.',
            $new_constraint_def
          );
          $alter_sql[] =
            "ADD CONSTRAINT $new_constraint_name $constraint_def"
          ;
          // Skip impplicit indexes.
          if (preg_match('/(?:^|\s)(?:UNIQUE|PRIMARY\s+KEY)(?:\s|$)/', $constraint_def)) {
            $index_to_skip[$new_constraint_name] = TRUE;
          }
        }
      }

      // Alter table.
      if (!empty($alter_sql)) {
        $sql_query =
          "ALTER TABLE "
          . $chado_schema_quoted
          . ".$new_table_name\n  "
          . implode(",\n  ", $alter_sql)
          . ';'
        ;
        $this->upgradeQueries[$upgq_id][] = $sql_query;
      }

      // Create new indexes.
      foreach ($new_table_def['indexes'] as $new_index_name => $new_index_def) {
        if (!isset($index_to_skip[$new_index_name])) {
          $index_def = str_replace(
            $ref_chado_schema_quoted . '.',
            $chado_schema_quoted . '.',
            $new_index_def['query']
          );
          $this->upgradeQueries[$upgq_id][] = $index_def;
        }
        // Add comment if one.
        $sql_query =
          "SELECT
            'COMMENT ON INDEX $chado_schema_quoted.' || quote_ident(c.relname) || ' IS ' || quote_literal(d.description) AS \"comment\"
          FROM pg_class c
            JOIN pg_namespace n ON (n.oid = c.relnamespace)
            JOIN pg_index i ON (i.indexrelid = c.oid)
            JOIN pg_description d ON (d.objoid = c.oid)
          WHERE
            c.reltype = 0
            AND n.nspname = :ref_schema
            AND c.relname = :index_name;"
        ;
        $comment_result = $connection->query(
            $sql_query,
            [
              ':ref_schema' => $ref_chado_schema,
              ':index_name' => $new_index_name,
            ]
          )
          ->fetch()
        ;
        if (!empty($comment_result) && !empty($comment_result->comment)) {
          $this->upgradeQueries[$upgq_id][] = $comment_result->comment . ';';
        }
      }
    }

    // Third loop adds foreign key contraints.
    foreach ($new_tables as $new_table_name => $new_table) {
      // Check if table should be skipped.
      if (array_key_exists($new_table_name, $skip_table_column)
          && empty($skip_table_column[$new_table_name])) {
        continue;
      }

      $upgq_id = $new_table_name . ' 3rd pass';
      if (!isset($this->upgradeQueries[$upgq_id])) {
        $this->upgradeQueries[$upgq_id] = [];
      }

      $alter_sql = [];
      $new_table_def = $new_table_definitions[$new_table_name];
      $new_cstr_def = $new_table_def['constraints'];
      $index_to_skip = [];

      foreach ($new_cstr_def as $new_constraint_name => $new_constraint_def) {
        // Only foreign keys.
        if (preg_match('/(?:^|\s)FOREIGN\s+KEY(?:\s|$)/', $new_constraint_def)) {
          $constraint_def = str_replace(
            $ref_chado_schema_quoted . '.',
            $chado_schema_quoted . '.',
            $new_constraint_def
          );
          $alter_sql[] =
            "ADD CONSTRAINT $new_constraint_name $constraint_def"
          ;
        }
      }

      // Alter table.
      if (!empty($alter_sql)) {
        $sql_query =
          "ALTER TABLE "
          . $chado_schema_quoted
          . ".$new_table_name\n  "
          . implode(",\n  ", $alter_sql)
          . ';'
        ;
        $this->upgradeQueries[$upgq_id][] = $sql_query;
      }
    }

    // Report table changes.
    if (!empty($old_tables)) {
      if ($cleanup) {
        foreach ($old_tables as $old_table_name => $old_table) {
          $sql_query =
            "DROP TABLE IF EXISTS "
            . $chado_schema_quoted
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
   * Associate sequences.
   *
   * @param array $parameters
   *   Upgrade parameters (chado_schema, ref_chado_schema, cleanup, version)
   */
  protected function prepareSequenceAssociation($parameters) {
    $connection              = $this->connection;
    $chado_schema_quoted     = $parameters['chado_schema_quoted'];
    $ref_chado_schema        = $parameters['ref_chado_schema'];

    // Get the list of new sequences.
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
          . $chado_schema_quoted
          . '.'
          . $sequence
          . ' OWNED BY '
          . $chado_schema_quoted
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
   * Upgrade views.
   *
   * @param array $parameters
   *   Upgrade parameters (chado_schema, ref_chado_schema, cleanup, version)
   */
  protected function prepareUpgradeViews($parameters) {
    $connection              = $this->connection;
    $chado_schema_quoted     = $parameters['chado_schema_quoted'];
    $ref_chado_schema        = $parameters['ref_chado_schema'];
    $ref_chado_schema_quoted = $parameters['ref_chado_schema_quoted'];

    // Get the list of new views.
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
        ':regex_search' => '(^|\W)' . $ref_chado_schema_quoted. '\.',
        ':regex_replace' => '\1' . $chado_schema_quoted . '.',
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
          . $chado_schema_quoted
          . "."
          . $view->table_name
          . " IS " . pg_escape_literal($comment->comment)
          . ';'
        ;
        $this->upgradeQueries[$view->table_name][] = $sql_query;
      }
    }
  }

  /**
   * Upgrade functions.
   *
   * @param array $parameters
   *   Upgrade parameters (chado_schema, ref_chado_schema, cleanup, version)
   */
  protected function prepareFunctionUpgrade($parameters) {
    $connection              = $this->connection;
    $chado_schema            = $parameters['chado_schema'];
    $chado_schema_quoted     = $parameters['chado_schema_quoted'];
    $ref_chado_schema        = $parameters['ref_chado_schema'];
    $ref_chado_schema_quoted = $parameters['ref_chado_schema_quoted'];
    $cleanup                 = $parameters['cleanup'];

    // Get the list of new functions.
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
            '" . $ref_chado_schema_quoted . "\\.',
            '" . $chado_schema_quoted . ".',
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
      $this->upgradeQueries[$object_id][] = $func->def . ';';
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
          . $chado_schema_quoted
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
   * @param array $parameters
   *   Upgrade parameters (chado_schema, ref_chado_schema, cleanup, version)
   */
  protected function prepareAggregateFunctionUpgrade($parameters) {
    $connection              = $this->connection;
    $chado_schema            = $parameters['chado_schema'];
    $chado_schema_quoted     = $parameters['chado_schema_quoted'];
    $ref_chado_schema        = $parameters['ref_chado_schema'];
    $ref_chado_schema_quoted = $parameters['ref_chado_schema_quoted'];
    $cleanup                 = $parameters['cleanup'];

    // Get the list of new aggregate functions.
    $sql_query = "
      SELECT
        p.proname AS \"proname\",
        p.proname
          || '('
          ||  pg_get_function_identity_arguments(p.oid)
          || ')'
        AS \"proident\",
        'DROP AGGREGATE IF EXISTS "
      . $chado_schema_quoted
      . ".'
        || p.proname
        || '('
        || format_type(a.aggtranstype, NULL)
        || ')' AS \"drop\",
        'CREATE AGGREGATE "
      . $chado_schema_quoted
      . ".'
        || p.proname
        || '('
        || format_type(a.aggtranstype, NULL)
        || ') (sfunc = '
        || regexp_replace(a.aggtransfn::text, '(^|\\W)"
      . $ref_chado_schema_quoted
      . "\\.', '\\1"
      . $chado_schema_quoted
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
      $this->upgradeQueries[$object_id][] = $aggrfunc->drop . ';';
      $this->upgradeQueries[$object_id][] = $aggrfunc->def . ';';
      $official_aggregate[$aggrfunc->drop] = TRUE;
    }

    // Cleanup if needed.
    if ($cleanup) {
      $sql_query = "
        SELECT
          'DROP AGGREGATE IF EXISTS " . $chado_schema_quoted . ".'
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
          $this->upgradeQueries['#cleanup'][] = $aggrfunc->drop . ';';
          $dropped[] = preg_replace(
            '/DROP AGGREGATE IF EXISTS ([^\)]+\))/',
            '\1',
            $aggrfunc->drop
          );
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
   * Upgrade table column defaults.
   *
   * @param array $parameters
   *   Upgrade parameters (chado_schema, ref_chado_schema, cleanup, version)
   */
  protected function prepareUpgradeTableDefauls($parameters) {
    $connection              = $this->connection;
    $chado_schema_quoted     = $parameters['chado_schema_quoted'];
    $ref_chado_schema        = $parameters['ref_chado_schema'];
    $ref_chado_schema_quoted = $parameters['ref_chado_schema_quoted'];

    // Get tables.
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
      $this->upgradeQueries[$new_table_name . ' set default'] = [];

      // Get new table definition.
      $sql_query = "SELECT public.tripal_get_table_ddl('$ref_chado_schema', '$new_table_name', TRUE) AS \"definition\";";
      $new_table_raw_definition = explode(
        "\n",
        $connection->query($sql_query)->fetch()->definition
      );
      $new_table_definition = $this->parseTableDdl($new_table_raw_definition);

      $new_column_defs = $new_table_definition['columns'];
      foreach ($new_column_defs as $new_column => $new_column_def) {
        // Replace schema name if there.
        $new_default = str_replace(
          $ref_chado_schema_quoted . '.',
          $chado_schema_quoted . '.',
          $new_column_def['default']
        );
        if (!empty($new_default)) {
          $sql_query =
            "ALTER TABLE "
            . $chado_schema_quoted
            . ".$new_table_name ALTER COLUMN $new_column SET "
            . $new_default
            . ';';
          $this->upgradeQueries[$new_table_name . ' set default'][] =
            $sql_query
          ;
        }
      }
    }
  }

  /**
   * Upgrade comment.
   *
   * @param array $parameters
   *   Upgrade parameters (chado_schema, ref_chado_schema, cleanup, version)
   */
  protected function prepareCommentUpgrade($parameters) {
    $connection              = $this->connection;
    $chado_schema            = $parameters['chado_schema'];
    $ref_chado_schema        = $parameters['ref_chado_schema'];
    $cleanup                 = $parameters['cleanup'];

    $this->upgradeQueries['#comments'] = [];

    $column_with_comment = [];
    // Find comment on columns.
    $sql_query = "
      SELECT
        n.nspname,
        cs.relname,
        a.attname,
        d.description
      FROM pg_class cs
        JOIN pg_namespace n ON (n.oid = cs.relnamespace)
        JOIN pg_description d ON (d.objoid = cs.oid)
        JOIN pg_attribute a ON (a.attrelid = cs.oid AND d.objsubid = a.attnum)
      WHERE n.nspname = :schema;
    ";
    $column_comments = $connection
      ->query($sql_query, [':schema' => $ref_chado_schema])
      ->fetchAll()
    ;
    foreach ($column_comments as $column_comment) {
      $this->upgradeQueries['#comments'][] =
        'COMMENT ON COLUMN '
        . $column_comment->relname
        . '.'
        . $column_comment->attname
        . ' IS '
        . pg_escape_literal($column_comment->description)
        . ';'
      ;
      if (!array_key_exists($column_comment->relname, $column_with_comment)) {
        $column_with_comment[$column_comment->relname] = [];
      }
      // Keep track of what is commented.
      $column_with_comment[$column_comment->relname][$column_comment->attname]
        = TRUE;
    }

    // Drop old comments.
    $sql_query = "
      SELECT
        n.nspname,
        cs.relname,
        a.attname,
        d.description
      FROM pg_class cs
        JOIN pg_namespace n ON (n.oid = cs.relnamespace)
        JOIN pg_description d ON (d.objoid = cs.oid)
        JOIN pg_attribute a ON (a.attrelid = cs.oid AND d.objsubid = a.attnum)
      WHERE n.nspname = :schema;
    ";
    $old_column_comments = $connection
      ->query($sql_query, [':schema' => $chado_schema])
      ->fetchAll()
    ;
    foreach ($old_column_comments as $old_column_comment) {
      $table = $old_column_comment->relname;
      $column = $old_column_comment->attname;
      $no_comment = empty($column_with_comment[$table][$column]);
      if ($no_comment) {
        if ($cleanup) {
          $this->upgradeQueries['#comments'][] =
            'COMMENT ON COLUMN '
            . $old_column_comment->relname
            . '.'
            . $old_column_comment->attname
            . ' IS NULL;'
          ;
        }
        else {
          \Drupal::messenger()->addWarning(
            t(
              'The comment on column %table.%column can be removed.',
              [
                '%table' => $old_column_comment->relname,
                '%column' => $old_column_comment->attname,
              ]
            )
          );
        }
      }
    }
  }

  /**
   * Add missing initialization data.
   *
   * @param array $parameters
   *   Upgrade parameters (chado_schema, ref_chado_schema, cleanup, version)
   */
  protected function reinitSchema($parameters) {
    $connection          = $this->connection;
    $chado_schema_quoted = $parameters['chado_schema_quoted'];
    $version             = $parameters['version'];

    // Get initialization script.
    $module_path = drupal_get_path('module', 'tripal_chado');
    $sql_file = $module_path . '/chado_schema/initialize-' . $version . '.sql';
    $sql = file_get_contents($sql_file);
    // Remove any search_path change containing 'chado' as a schema name.
    $sql = preg_replace(
      '/^(?:(?!\s*--)[^;]*;)*\s*SET\s*search_path\s*=\s*(?:[^;]+,|)chado(,[^;]+|)\s*;/im',
      '',
      $sql
    );
    $this->upgradeQueries['#init'] = [$sql];
    $this->upgradeQueries['#init'][] = "
      INSERT INTO "
      . $chado_schema_quoted
      . ".chadoprop (type_id, value, rank)
      VALUES (
        (
          SELECT cvterm_id
          FROM "
      . $chado_schema_quoted
      . ".cvterm CVT
            INNER JOIN "
      . $chado_schema_quoted
      . ".cv CV on CVT.cv_id = CV.cv_id
          WHERE CV.name = 'chado_properties' AND CVT.name = 'version'
        ),
        '$version',
        0
      ) ON CONFLICT (type_id, rank) DO UPDATE SET value = '$version';
    ";
  }

  /**
   * Process upgrades.
   *
   * Execute SQL queries or save them into a SQL instead if $filename is set.
   * Queries are ordered according to priorities and what must be run in the
   * end.
   *
   * @param string $filename
   *   File to use to store SQL queries.
   */
  protected function processUpgradeQueries($priorities, $filename = NULL) {
    $pg_connection = $this->getPgConnection();
    $skip_objects = [];
    $fh = FALSE;
    if (!empty($filename)) {
      $fh = fopen($filename, 'w');
      if (!$fh) {
        throw new \Exception("Failed to open '$filename' for writting!");
      }
    }

    foreach ($this->upgradeQueries as $object_id => $upgrade_queries) {
      // Skip #end elements that will be processed in the end.
      if ('#end' == $object_id) {
        continue;
      }
      // Process prioritized objects now (remove them from the regular queue and
      // add them to the priorities queue).
      if ('#priorities' == $object_id) {
        foreach ($priorities as $priority) {
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
            throw new \Exception(
              "Upgrade query failed for query:\n$sql_query\nERROR: "
              . pg_last_error()
            );
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
   * Return table dependencies.
   *
   * @TODO: migrate to Chado API
   *
   * @param $chado_schema
   *   Name of the schema to process.
   *
   * @return array
   *   first-level keys are table name, second level keys are column names,
   *   third level keys are foreign table names and values are foreign column
   *   names.
   */
  protected function getTableDependencies($chado_schema) {
    $connection = $this->connection;
    // Get tables.
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
      $table_definition = $this->parseTableDdl($table_raw_definition);

      // Process FK constraints.
      $foreign_keys = [];
      $cstr_defs = $table_definition['constraints'];
      foreach ($cstr_defs as $constraint_name => $constraint_def) {
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
            $sql_queries[] =
              "ALTER $ref_chado_schema.analysis ALTER COLUMN analysis_id ...";
            $sql_queries[] =
              "CREATE TABLE $ref_chado_schema.analysis_cvterm ...";
            $sql_queries[] =
              "INSERT INTO $ref_chado_schema.analysis_cvterm ...";
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
