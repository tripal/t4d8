<?php

namespace Drupal\tripal_chado\Services;

use Drupal\Core\Database\Database;
use Drupal\tripal\Services\bulkPgSchemaInstaller;

class chadoUpgrader extends bulkPgSchemaInstaller {

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
   * Upgrade Chado schema to the specified version.
   *
   * We create a new schema having the same name but ending with '¥'. We
   * install an empty Chado schema in that schema as a structure reference. We
   * then iterate on each object of that schema to adjust the corresponding
   * object in the schema to upgrade.
   *
   * @param float $version
   *   The version of chado you would like to upgrade to.
   */
  public function upgrade($version, $cleanup = FALSE) {
    $this->newVersion = $version;
    // Save schema name to upgrade.
    $chado_schema = $this->schemaName;

    // Appends the ¥ sign (UTF8 \xC2\xA5 or ASCII \xBE).
    $ref_chado_schema = '_chado_upgrade_tmp_¥';

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
//+debug      $this->logger->error(
//+debug        'Temporary reference schema "'
//+debug        . $ref_chado_schema
//+debug        . '" already exists. Please remove that schema first if you did not create it (previous unsuccessfull upgrade) or rename it otherwise.'
//+debug      );
//+debug      return FALSE;
    }
    // Validations ok, save previous search_path.
    $sql_query = "SELECT setting FROM pg_settings WHERE name = 'search_path';";
    $old_search_path = $connection->query($sql_query)->fetch()->setting ?: "''";

if (FALSE) { //+debug
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

} //+debug

    try {
      // Put back specified schema name.
      $this->schemaName = $chado_schema;
      // And make sure we workd in this current schema.
      $sql_query = "SET search_path = " . $this->schemaNameQuoted . ";";
      $connection->query($sql_query);
      pg_query($this->getPgConnection(), $sql_query);

      // 3) Compare schema structures...
      // - Collations: ignored, not relevant.
      // - Domains: ignored, not relevant.
      // - Check types.
      $this->upgradeTypes($chado_schema, $ref_chado_schema, $cleanup);

      // - Remove column defaults.
      $this->dropAllColumnDefaults($chado_schema, $ref_chado_schema);

      // - Create prototype functions.
      $this->createPrototypeFunctions($chado_schema, $ref_chado_schema);

      // - Upgrade aggregate functions.
      $this->upgradeAggregateFunctions($chado_schema, $ref_chado_schema, $cleanup);

      // - Upgrade existing sequences and add missing ones.
      $this->upgradeSequences($chado_schema, $ref_chado_schema, $cleanup);

      // - Drop old views to remove dependencies on tables.
      $this->dropAllViews($chado_schema);

      // - Tables.
      $this->upgradeTables($chado_schema, $ref_chado_schema, $cleanup);

      // - Sequence associations.
      $this->associateSequences($chado_schema, $ref_chado_schema);

      // - Views.
      $this->upgradeViews($chado_schema, $ref_chado_schema);

      // - Upgrade functions (fill function bodies).
      $this->upgradeFunctions($chado_schema, $ref_chado_schema, $cleanup);

      // - Triggers: ignored, not relevant.
      // - Materialized views: ignored, not relevant.

      // - Upgrade comments.
      $this->upgradeComments($chado_schema, $ref_chado_schema, $cleanup);

      // x) Check if schema is integrated into Tripal and update version if needed.
    }
    catch (Exception $e) {
      // Restore search_path.
      $sql_query = "SET search_path = $old_search_path;";
      $connection->query($sql_query);
      pg_query($this->getPgConnection(), $sql_query);

      // Drop temporary schema.
//+debug      $this->dropSchema($ref_chado_schema);

      // Rethrow exception.
      throw $e;
    }

    // x) Restore search_path.
    $sql_query = "SET search_path = $old_search_path;";
    $connection->query($sql_query);
    pg_query($this->getPgConnection(), $sql_query);

    // x) Remove temporary reference schema.
//+debug    $this->dropSchema($ref_chado_schema);
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
  protected function upgradeTypes(
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
          $sql_query =
            "DROP TYPE IF EXISTS "
            . $this->schemaNameQuoted
            . ".$new_type_name CASCADE;";
          $connection->query($sql_query);
          $sql_query =
            "CREATE TYPE "
            . $this->schemaNameQuoted
            . ".$new_type_name AS "
            . ($new_type->typcategory == 'E' ? 'ENUM ' : '')
            . "("
            . $new_type->typdef
            . ");"
          ;
          $connection->query($sql_query);
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
        $sql_query =
          "CREATE TYPE "
          . $this->schemaNameQuoted
          . ".$new_type_name AS "
          . ($new_type->typcategory == 'E' ? 'ENUM ' : '')
          . "("
          . $new_type->typdef
          . ");"
        ;
        $connection->query($sql_query);
      }
    }
    // Report type changes.
    if (!empty($old_types)) {
      if ($cleanup) {
        // Remove old types.
        foreach ($old_types as $old_type_name => $old_type) {
          $sql_query =
            "DROP TYPE IF EXISTS "
            . $this->schemaNameQuoted
            . ".$old_type_name CASCADE;"
          ;
          $connection->query($sql_query);
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
  protected function dropAllColumnDefaults(
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
    ";
    $tables = $connection
      ->query($sql_query, [':schema' => $chado_schema])
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
          $sql_query =
            "ALTER TABLE "
            . $this->schemaNameQuoted
            . '.'
            . $table
            . " ALTER COLUMN $column DROP DEFAULT;"
          ;
          $connection->query($sql_query);
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
  protected function createPrototypeFunctions(
    $chado_schema,
    $ref_chado_schema
  ) {
    $connection = $this->connection;
    $pgconnection = $this->getPgConnection();

    // Get the list of new functions.
    // Here we don't use {} for tables as these are system tables.
//    $sql_query = "
//        SELECT
//          regexp_replace(
//            regexp_replace(
//              regexp_replace(
//                pg_get_functiondef(p.oid),
//                :regex_search,
//                :regex_replace,
//                'is'
//              ),
//              :regex_search2,
//              :regex_replace2,
//              'is'
//            ),
//            '" . $this->refSchemaNameQuoted . "\\.',
//            '" . $this->schemaNameQuoted . ".',
//            'gis'
//          ) AS \"proto\"
//          FROM pg_proc p
//            JOIN pg_namespace n ON pronamespace = n.oid
//        WHERE
//          n.nspname = :ref_schema
//          AND prokind != 'a'
//        ;
//    ";
    $sql_query = "
      SELECT
        p.proname AS \"proname\",
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
//        // First pass replaces function options and body by and empty body.
//        // Matches everything to the first ')' and replace the rest.
//        ':regex_search' => '^([^\)]+\)).*$',
//        ':regex_replace' => '\1 RETURNS VOID LANGUAGE plpgsql AS $_$BEGIN END;$_$;',
//        // Second pass removes the RETURNS part if there are OUT/INOUT
//        // parameters.
//        // Matches everything inside the parenthesis that has something like
//        // " OUT,", " INOUT,", " INOUT)" or " OUT)" and removes the "RETURNS
//        // VOID" part if so.
//        ':regex_search2' => '(\((?:.+\s(?:IN)?OUT\s*,.+|.+\s(?:IN)?OUT\s*)\))\s* RETURNS VOID ',
//        ':regex_replace2' => '\1',
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
//      $sql_query = 'DROP FUNCTION IF EXISTS ' . $this->schemaNameQuoted . '.' . $proto_func->proname . ' CASCADE;';
      $proto_query = str_replace('#', ';', $proto_func->proto);
      // Create new one.
      $result =
        pg_query($pgconnection, $sql_query)
        && pg_query($pgconnection, $proto_query)
      ;
      if (!$result) {
        // Failed.
        throw new \Exception(
          'Failed to generate prototype function: '
          . $proto_query
        );
      }
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
  protected function upgradeSequences(
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
        " SEQUENCE $chado_schema.$new_seq_name"
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
          $connection->query('ALTER' . $create_update_seq_sql_query);
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
        $connection->query('CREATE' . $create_update_seq_sql_query);
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
          $connection->query($sql_query);
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
  protected function dropAllViews(
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
      $sql_query = "DROP VIEW IF EXISTS $chado_schema.$view CASCADE;";
      $connection->query($sql_query);
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
        if (preg_match('/^\s*CREATE\s+(?:UNIQUE\s+)?INDEX\s+(?:CONCURRENTLY\s+)?(?:IF\s+NOT\s+EXISTS\s+)?([\w\$\x80-\xFF\.]+)\s+ON\s+([\w\$\x80-\xFF\."]+)\s+USING\s+(.+);\s*$/i', $table_ddl[$i], $match)) {
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
  protected function upgradeTables(
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
    // Check for missing or changed tables.
    foreach ($new_tables as $new_table_name => $new_table) {
print "DEBUG: working on table $new_table_name\n"; //+debug
      if (array_key_exists($new_table_name, $old_tables)) {

        // Exists, compare.
        $old_table = $old_tables[$new_table_name];

        // Get new table definition.
        $sql_query = "SELECT public.tripal_get_table_ddl('$ref_chado_schema', '$new_table_name', TRUE) AS \"definition\";";
        $new_table_raw_definition = explode("\n", $connection->query($sql_query)->fetch()->definition);
        $new_table_definition = $this->parse_table_ddl($new_table_raw_definition);

        // Get old table definition.
        $sql_query = "SELECT public.tripal_get_table_ddl('$chado_schema', '$new_table_name', TRUE) AS \"definition\";";
        $old_table_raw_definition = explode("\n", $connection->query($sql_query)->fetch()->definition);
        $old_table_definition = $this->parse_table_ddl($old_table_raw_definition);

        $are_different = FALSE;

        // Start comparison.
        $alter_sql = [];
        // Compare columns.
        foreach ($new_table_definition['columns'] as $new_column => $new_column_def) {
          // @todo: Maybe use a lookup table first to provide a way to run
          // some specific updates (column renaming, value alteration, ...).
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
            // DEFAULT value.
            if ($old_table_definition['columns'][$new_column]['default'] != $new_default) {
              if ($new_default) {
                $alter_sql[] =
                  "ALTER COLUMN $new_column SET" . $new_default;
              }
              else {
                $alter_sql[] = "ALTER COLUMN $new_column DROP DEFAULT";
              }
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
print "DEBUG: $sql_query\n"; //+debug
          $connection->query($sql_query);
          $processed_new_tables[] = $new_table_name;
        }

        // Process indexes.
        // Remove all old indexes.
print "DEBUG INDEXES: " . print_r($old_table_definition['indexes'], TRUE) . "\n"; //+debug
        foreach ($old_table_definition['indexes'] as $old_index_name => $old_index_def) {
          $sql_query = "DROP INDEX IF EXISTS " . $this->schemaNameQuoted . ".$old_index_name CASCADE;";
print "DEBUG:   $sql_query\n"; //+debug
          $connection->query($sql_query);
        }

        // Create new indexes.
        foreach ($new_table_definition['indexes'] as $new_index_name => $new_index_def) {
          $index_def = str_replace($this->refSchemaNameQuoted . '.', $this->schemaNameQuoted . '.', $new_index_def['query']);
print "DEBUG:   $index_def\n"; //+debug
          $connection->query($index_def);
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
print "DEBUG: $sql_query\n"; //+debug
        $connection->query($sql_query);
        $processed_new_tables[] = $new_table_name;

        // Update references to functions in column defaults.
        $sql_query = "
          SELECT column_name,
             regexp_replace(
              column_default::text,
              :regex_search,
              :regex_replace,
              'gis'
            ) AS \"default\"
          FROM information_schema.columns
          WHERE
            table_schema = :schema
            AND TABLE_NAME = :table_name
            AND column_default ~* ('(^|\W)" . $this->refSchemaNameQuoted . "\.')
        ";
        $result = $connection
          ->query($sql_query, [
            ':schema' => $chado_schema,
            ':table_name' => $new_table_name,
            ':regex_search' => '(^|\W)' . $this->refSchemaNameQuoted . '\.',
            ':regex_replace' => '\1' . $this->schemaNameQuoted . '.',
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
              . ' SET DEFAULT '
              . $column->default
            ;
            $connection->query($sql_query);
          }
        }

      }

      // Add comment.
      $sql_query = "COMMENT ON TABLE " . $this->schemaNameQuoted . ".$new_table_name IS :comment";
      $connection->query($sql_query, [':comment' => $new_table->comment]);
    }


    // Add table contraints.
    foreach ($new_tables as $new_table_name => $new_table) {
print "DEBUG: constraints for $new_table_name\n"; //+debug
      $alter_first_sql = [];
      $alter_next_sql = [];
      $new_table_definition = $new_table_definitions[$new_table_name];
      foreach ($new_table_definition['constraints'] as $new_constraint_name => $new_constraint_def) {
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
print "DEBUG: $sql_query\n"; //+debug
        $connection->query($sql_query);
      }
      if (!empty($alter_next_sql)) {
        $sql_query =
          "ALTER TABLE " . $this->schemaNameQuoted . ".$new_table_name\n  "
          . implode(",\n  ", $alter_next_sql)
        ;
print "DEBUG: $sql_query\n"; //+debug
        $connection->query($sql_query);
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
          $connection->query($sql_query);
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
   * Upgrade functions.
   *
   * @param $chado_schema
   *   Name of the schema to upgrade.
   * @param $ref_chado_schema
   *   Name of the reference schema to use for upgrade.
   * @param $cleanup
   *   Remove functions not defined in the official Chado release.
   */
  protected function upgradeFunctions(
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
      $result = pg_query($pgconnection, $func->def);
      if (!$result) {
        // Failed.
        throw new \Exception('Failed to upgrade function: ' . $func->def);
      }
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
        $connection->query($sql_query);
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
  protected function upgradeAggregateFunctions(
    $chado_schema,
    $ref_chado_schema,
    $cleanup
  ) {
    $connection = $this->connection;
    // Get the list of new aggregate functions.
    // Here we don't use {} for tables as these are system tables.
    $sql_query = "
      SELECT
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
      $result =
        pg_query($pgconnection, $aggrfunc->drop)
        && pg_query($pgconnection, $aggrfunc->def)
      ;
      if (!$result) {
        // Failed.
        throw new \Exception('Failed to create aggregate function: ' . preg_replace('/^CREATE AGGREGATE ([^\)]+\))/s', '\1', $aggrfunc->def));
      }
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
          $connection->query($aggrfunc->drop);
          $dropped[] = preg_replace('/DROP AGGREGATE ([^\)]+\))/', '\1', $aggrfunc->drop);
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
   */
  protected function upgradeViews(
    $chado_schema,
    $ref_chado_schema
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
      $connection->query(
        'CREATE OR REPLACE VIEW '
        . $view->table_name
        . ' AS '
        . $view->def
      );
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
          . " IS :comment;"
        ;
        $connection->query($sql_query, [':comment' => $comment->comment]);
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
  protected function associateSequences(
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
        $connection->query($sql_query);
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
   */
  protected function upgradeComments(
    $chado_schema,
    $ref_chado_schema,
    $cleanup
  ) {
    $connection = $this->connection;
    // Find comment on columns.
    $sql_query = ";";
    //+TODO
  }

}