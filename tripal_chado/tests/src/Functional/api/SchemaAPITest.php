<?php

namespace Drupal\Tests\tripal_chado;

use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use Drupal\Core\Database\Database;
use Drupal\tripal_chado\api\ChadoSchema;

/**
 * Testing the tripal_chado/api/tripal_chado.schema.api.inc functions.
 *
 * @group Tripal
 * @group Tripal Chado
 * @group Tripal Database
 * @group Tripal API
 */
class SchemaAPITest extends BrowserTestBase {

  protected $defaultTheme = 'stable';

  /**
   * Modules to enable.
   * @var array
   */
  protected static $modules = ['tripal', 'tripal_chado'];

  /**
   * Schema to do testing out of.
   * @var string
   */
  protected static $schemaName = 'testchado';

  /**
   * Tests ChadoSchema static functions.
   *
   * @group tripal-chado
   * @group chado-schema
   */
  public function testChadoSchemaStaticAPI() {
    $connection = Database::getConnection();

    // Tests on schema name function...
    // Empty name.
    $schema_issue = ChadoSchema::isInvalidSchemaName('');
    $this->assertNotEmpty($schema_issue, 'ChadoSchema::isInvalidSchemaName(): invalidating empty schema name.');

    // Reserved names.
    $schema_issue = ChadoSchema::isInvalidSchemaName('public');
    $this->assertNotEmpty($schema_issue, 'ChadoSchema::isInvalidSchemaName(): invalidating "public" schema name.');
    $schema_issue = ChadoSchema::isInvalidSchemaName('pg_catalog');
    $this->assertNotEmpty($schema_issue, 'ChadoSchema::isInvalidSchemaName(): invalidating "pg_catalog" reserved schema name.');
    $schema_issue = ChadoSchema::isInvalidSchemaName('pg_');
    $this->assertNotEmpty($schema_issue, 'ChadoSchema::isInvalidSchemaName(): invalidating "pg_*" reserved schema names.');

    // Starting with a number.
    $schema_issue = ChadoSchema::isInvalidSchemaName('4chado');
    $this->assertNotEmpty($schema_issue, 'ChadoSchema::isInvalidSchemaName(): invalidating schema name starting by a number.');
    $schema_issue = ChadoSchema::isInvalidSchemaName('42chado');
    $this->assertNotEmpty($schema_issue, 'ChadoSchema::isInvalidSchemaName(): invalidating schema name starting by a number.');
    
    // Invalid characters.
    $schema_issue = ChadoSchema::isInvalidSchemaName('cha do');
    $this->assertNotEmpty($schema_issue, 'ChadoSchema::isInvalidSchemaName(): invalidating schema name with invalid characters - test 1.');
    $schema_issue = ChadoSchema::isInvalidSchemaName('chado!');
    $this->assertNotEmpty($schema_issue, 'ChadoSchema::isInvalidSchemaName(): invalidating schema name with invalid characters - test 2.');
    $schema_issue = ChadoSchema::isInvalidSchemaName('chado>');
    $this->assertNotEmpty($schema_issue, 'ChadoSchema::isInvalidSchemaName(): invalidating schema name with invalid characters - test 3.');
    $schema_issue = ChadoSchema::isInvalidSchemaName('chado]');
    $this->assertNotEmpty($schema_issue, 'ChadoSchema::isInvalidSchemaName(): invalidating schema name with invalid characters - test 4.');
    $schema_issue = ChadoSchema::isInvalidSchemaName('chado}');
    $this->assertNotEmpty($schema_issue, 'ChadoSchema::isInvalidSchemaName(): invalidating schema name with invalid characters - test 5.');
    $schema_issue = ChadoSchema::isInvalidSchemaName("chado\x7F");
    $this->assertNotEmpty($schema_issue, 'ChadoSchema::isInvalidSchemaName(): invalidating schema name with invalid characters - test 6.');
    // Name too long.
    $schema_issue = ChadoSchema::isInvalidSchemaName("chadochadochadochadochadochadochadochadochadochadochadochadochado");
    $this->assertNotEmpty($schema_issue, 'ChadoSchema::isInvalidSchemaName(): invalidating too long schema name.');

    // Valid names.
    $schema_issue = ChadoSchema::isInvalidSchemaName("chado");
    $this->assertEmpty($schema_issue, 'ChadoSchema::isInvalidSchemaName(): validating very basic schema name.');
    $schema_issue = ChadoSchema::isInvalidSchemaName("tripal_chado_v2");
    $this->assertEmpty($schema_issue, 'ChadoSchema::isInvalidSchemaName(): validating basic schema name.');
    // Latin1 version of "ÇhàÐº ø" in UTF8.
    $schema_issue = ChadoSchema::isInvalidSchemaName("\xC7h\xE0\xD0\xBA\xA0\xF8");
    $this->assertEmpty($schema_issue, 'ChadoSchema::isInvalidSchemaName(): validating complex schema name.');

    // Check a random schema name does not exist.
    $exists = ChadoSchema::schemaExists('jlmoosilskswbpeqtdurbsfpamiwiwl');
    $this->assertFalse($exists, 'ChadoSchema::schemaExists(): A non-existing Chado schema is not available.');

    // Check test mode.
    $schema_issue = ChadoSchema::isInvalidSchemaName(SchemaAPITest::$schemaName);
    $this->assertNotEmpty($schema_issue, 'ChadoSchema::isInvalidSchemaName(): invalidating "testchado" reserved schema name by default.');
    ChadoSchema::testMode(TRUE);
    $schema_issue = ChadoSchema::isInvalidSchemaName(SchemaAPITest::$schemaName);
    $this->assertEmpty($schema_issue, 'ChadoSchema::isInvalidSchemaName(): validating "testchado" schema name for tests.');

    // Check that test Chado schema exists.
    $exists = ChadoSchema::schemaExists(SchemaAPITest::$schemaName);
    $this->assertTrue($exists, 'ChadoSchema::schemaExists(): Test Chado schema available.');
  }

  /**
   * Tests chado_table_exists() and chado_column_exists().
   *
   * @group tripal-chado
   * @group chado-schema
   */
  public function testChadoTableColumnExists() {
    ChadoSchema::testMode(TRUE);
    $connection = Database::getConnection();

    // Check that chado exists.
    $check_schema = "SELECT true FROM pg_namespace WHERE nspname = :schema";
    $exists = $connection->query($check_schema, [':schema' => $this::$schemaName])
      ->fetchField();
    $this->assertTrue($exists, 'Cannot check chado schema api without chado.
      Please ensure chado is installed in the schema named "' . $this::$schemaName . '".');

    // Initialize ChadoSchema class to test new api.
    $chado_schema = new ChadoSchema(NULL, $this::$schemaName);

    // 1. Check that the table does not exist.
    $table_name = 'testChadoTableExists_' . uniqid();
    // -- Check the object-oriented ChadoSchema API.
    $result = $chado_schema->checkTableExists($table_name);
    $this->assertFalse($result,
      "The table should NOT exists because we haven't created it yet.");
    // -- Check the backwards-compaticle procedural schema api.
    $result = chado_table_exists($table_name, $this::$schemaName);
    $this->assertFalse($result,
      "BC: The table should NOT exists because we haven't created it yet.");

    // 2. Check that an existing table is correctly detected.
    // -- Now create the table.
    $sql = "CREATE TABLE " . $this::$schemaName . '.' . $table_name . " (
        cte_id      SERIAL PRIMARY KEY,
        cte_name    varchar(40)
    )";
    $connection->query($sql);
    // -- Check the object-oriented ChadoSchema API.
    $result = $chado_schema->checkTableExists($table_name);
    $this->assertTrue($result,
      "The table, $table_name, should exists because we just created it.");
    // -- Check the backwards-compaticle procedural schema api.
    $result = chado_table_exists($table_name, $this::$schemaName);
    $this->assertTrue($result,
      "BC: The table, $table_name, should exists because we just created it.");

    // 3. Check that a column NOT in the table is properly detected.
    $column = 'columndoesnotexist';
    // -- Check the object-oriented ChadoSchema API.
    $result = $chado_schema->checkColumnExists($table_name, $column);
    $this->assertFalse($result,
      "The column, $table_name.$column, should not exist in the table.");
    // -- Check the backwards-compaticle procedural schema api.
    $result = chado_column_exists($table_name, $column, $this::$schemaName);
    $this->assertFalse($result,
      "BC: The column, $table_name.$column, should not exist in the table.");

    // 4. Check that a column in the table is properly detected.
    $column = 'cte_name';
    // -- Check the object-oriented ChadoSchema API.
    $result = $chado_schema->checkColumnExists($table_name, $column);
    $this->assertTRUE($result,
      "The column, $table_name.$column, does exist in the table but we were not able to detect it.");
    // -- Check the backwards-compaticle procedural schema api.
    $result = chado_column_exists($table_name, $column, $this::$schemaName);
    $this->assertTRUE($result,
      "BC: The column, $table_name.$column, does exist in the table but we were not able to detect it.");

    // 5. Check for the sequence which allows the primary key to autoincrement.
    $sequence_name = strtolower($table_name . '_cte_id_seq');
    // -- Check the object-oriented ChadoSchema API.
    $result = $chado_schema->checkSequenceExists($table_name, 'cte_id');
    $this->assertTRUE($result,
      "The sequence, $sequence_name, should exist for the primary key.");
    // -- Check the backwards-compaticle procedural schema api.
    $result = chado_sequence_exists($sequence_name, $this::$schemaName);
    $this->assertTRUE($result,
      "BC: The sequence, $sequence_name, should exist for the primary key.");

    // 6. There is no sequence on the name so lets confirm that.
    $sequence_name = strtolower($table_name . '_cte_name_seq');
    // -- Check the object-oriented ChadoSchema API.
    $result = $chado_schema->checkSequenceExists($table_name, 'cte_name');
    $this->assertFALSE($result,
      "The sequence should NOT exist for the name.");
    // -- Check the backwards-compaticle procedural schema api.
    $result = chado_sequence_exists($sequence_name, $this::$schemaName);
    $this->assertFALSE($result,
      "BC: The sequence, $sequence_name, should NOT exist for the name.");

    // 7. Check for the index on the primary key.
    // -- Check the object-oriented ChadoSchema API.
    $result = $chado_schema->checkIndexExists($table_name, 'pkey', TRUE);
    $this->assertTRUE($result,
      "The index should exist for the primary key.");
    // -- Check the backwards-compaticle procedural schema api.
    $result = chado_index_exists($table_name, 'pkey', TRUE, $this::$schemaName);
    $this->assertTRUE($result,
      "BC: The index should exist for the primary key.");

    // 8. There is no index on the name so lets confirm that.
    $index = strtolower($table_name . '_cte_name_idx');
    // -- Check the object-oriented ChadoSchema API.
    $result = $chado_schema->checkIndexExists($table_name, 'cte_name');
    $this->assertFALSE($result,
      "The index should NOT exist for the name.");
    // -- Check the backwards-compaticle procedural schema api.
    $result = chado_index_exists($table_name, 'cte_name', $index, $this::$schemaName);
    $this->assertFALSE($result,
      "BC: The index should NOT exist for the name.");

    // 9. Check an existing index is propery detected.
    // We've already proven there is no index on the name.
    // Now we are going to add one!
    $success = $chado_schema->addIndex($table_name, 'cte_name', ['cte_name']);
    // -- Check the object-oriented ChadoSchema API.
    $result = $chado_schema->checkIndexExists($table_name, 'cte_name');
    $this->assertTrue($result,
      "The index we just created should be available.");
    // -- Check the backwards-compaticle procedural schema api.
    $result = chado_index_exists($table_name, 'cte_name', FALSE, $this::$schemaName);
    $this->assertTrue($result,
      "BC: The index we just created should be available.");

    // Clean up after ourselves by dropping the table.
    $connection->query("DROP TABLE ".$this::$schemaName . '.' . $table_name);
  }

  /**
   * Tests chado_get_schema_name().
   *
   * @group tripal-chado
   * @group chado-schema
   */
  public function testChadoSchemaMetdata() {
    ChadoSchema::testMode(TRUE);
    $connection = Database::getConnection();

    // Check that chado exists.
    $check_schema = "SELECT true FROM pg_namespace WHERE nspname = :schema";
    $exists = $connection->query($check_schema, [':schema' => $this::$schemaName])
      ->fetchField();
    $this->assertTrue($exists, 'Cannot check chado schema api without chado.
      Please ensure chado is installed in the schema named "' . $this::$schemaName . '".');

    // First check the default schema.
    $schema_name = chado_get_schema_name(uniqid());
    $this->assertEquals('public', $schema_name,
      "The default schema is not what we expected. We expected the 'public' schema.");

    // Next check if chado is local.
    $is_local = chado_is_local(TRUE, $this::$schemaName);
    $this->assertIsBool($is_local, "Unable to check that chado is local.");
    $is_local_2X = chado_is_local(FALSE, $this::$schemaName);
    $this->assertIsBool($is_local_2X, "Unable to check that chado is local 2X.");
    $this->assertEquals($is_local, $is_local_2X,
      "When checking if chado is local we didn't get the same answer twice.");

    // Check if chado is installed.
    $installed = chado_is_installed($this::$schemaName);
    $this->assertTrue($installed, "Chado is not installed?");

    // Check the chado version.
    $version = chado_get_version(FALSE, FALSE, $this::$schemaName);
    $this->assertGreaterThanOrEqual(1.3, $version,
      "We were unable to detect the version assuming it's 1.3");
  }

  /**
   * Tests that the class can be initiated with or without a record specified
   *
   * @group api
   * @group chado
   * @group chado-schema
   */
  public function testInitClass() {
    ChadoSchema::testMode(TRUE);

    // Test with no parameters.
    $chado_schema = new ChadoSchema(NULL, $this::$schemaName);
    $this->assertNotNull($chado_schema);

    // Test with version.
    $chado_schema = new ChadoSchema('1.3', $this::$schemaName);
    $this->assertNotNull($chado_schema);
  }

  /**
   * Tests the ChadoSchema->getVersion() method.
   *
   * @group api
   * @group chado
   * @group chado-schema
   */
  public function testGetVersion() {
    ChadoSchema::testMode(TRUE);

    // Generate a fake version.
    $version = rand(100,199) / 100;

    // Check version can be retrieved when we set it.
    $chado_schema = new ChadoSchema($version, $this::$schemaName);
    $retrieved_version = $chado_schema->getVersion();
    $this->assertEquals(
      $version,
      $retrieved_version,
      t('The version retrieved via ChadoSchema->getVersion, ":ret", should equal that set, ":set"',
        [':ret' => $retrieved_version, ':set' => $version])
    );

    // @todo Check version can be retrieved when it's looked up?
  }

  /**
   * Tests the ChadoSchema->getSchemaName() method.
   *
   * @group api
   * @group chado
   * @group chado-schema
   */
  public function testGetSchemaName() {
    ChadoSchema::testMode(TRUE);

    // Generate a fake version.
    $version = 1.3;

    // Check the schema name can be retrieved when we set it.
    $chado_schema = new ChadoSchema($version, $this::$schemaName);
    $retrieved_schema = $chado_schema->getSchemaName();
    $this->assertEquals(
      $this::$schemaName,
      $retrieved_schema,
      t('The schema name retrieved via ChadoSchema->getSchemaName, ":ret", should equal that set, ":set"',
        [':ret' => $retrieved_schema, ':set' => $this::$schemaName])
    );
  }

  /**
   * Tests the ChadoSchema->getSchemaDetails() method.
   *
   * @group api
   * @group chado
   * @group chado-schema
   */
  public function testGetSchemaDetails() {
    ChadoSchema::testMode(TRUE);
    $connection = Database::getConnection();

    // Check that chado exists.
    $check_schema = "SELECT true FROM pg_namespace WHERE nspname = :schema";
    $exists = $connection->query($check_schema, [':schema' => $this::$schemaName])
      ->fetchField();
    $this->assertTrue($exists, 'Cannot check chado schema api without chado.
      Please ensure chado is installed in the schema named "' . $this::$schemaName . '".');

    $chado_schema = new ChadoSchema(1.3, $this::$schemaName);
    $schema_details = $chado_schema->getSchemaDetails();
    $this->assertIsArray($schema_details,
      "We were unable to pull out the schema details from the YAML file.");
    $this->assertArrayHasKey('cvterm', $schema_details,
      "The schema details array does not contain details about the cvterm table.");
    $this->assertArrayHasKey('organism', $schema_details,
      "The schema details array does not contain details about the organism table.");
    $this->assertArrayHasKey('feature', $schema_details,
      "The schema details array does not contain details about the feature table.");
    $this->assertArrayHasKey('stock', $schema_details,
      "The schema details array does not contain details about the stock table.");

    foreach ($schema_details as $table => $table_details) {
      $this->assertArrayHasKey('description', $table_details,
        "The $table does not have a description in the YAML.");
      $this->assertArrayHasKey('fields', $table_details,
        "The $table does not have a fields array in the YAML.");
      $this->assertArrayHasKey('primary key', $table_details,
        "The $table does not have a primary key in the YAML.");
    }
  }

  /**
   * Tests ChadoSchema->getTableNames() method.
   *
   * @dataProvider knownTableProvider
   *
   * @group api
   * @group chado
   * @group chado-schema
   */
  public function testGetTableNames($version, $known_tables) {
    ChadoSchema::testMode(TRUE);
    $connection = Database::getConnection();

    // Check that chado exists.
    $check_schema = "SELECT true FROM pg_namespace WHERE nspname = :schema";
    $exists = $connection->query($check_schema, [':schema' => $this::$schemaName])
      ->fetchField();
    $this->assertTrue($exists, 'Cannot check chado schema api without chado.
      Please ensure chado is installed in the schema named "' . $this::$schemaName . '".');

    // Check: Known tables for a given version are returned.
    $chado_schema = new ChadoSchema($version, $this::$schemaName);
    $returned_tables = $chado_schema->getTableNames();
    //print_r($returned_tables);

    foreach ($known_tables as $table_name) {
      $this->assertContains(
        $table_name,
        $returned_tables,
        t('The table, ":known", should exist in the returned tables list for version :version.',
          [':known' => $table_name, ':version' => $version])
      );
    }
  }

  /**
   * Tests ChadoSchema->getTableSchema() method.
   *
   * @group api
   * @group chado
   * @group chado-schema
   */
  public function testGetTableSchema() {
    ChadoSchema::testMode(TRUE);
    $connection = Database::getConnection();

    // Check that chado exists.
    $check_schema = "SELECT true FROM pg_namespace WHERE nspname = :schema";
    $exists = $connection->query($check_schema, [':schema' => $this::$schemaName])
      ->fetchField();
    $this->assertTrue($exists, 'Cannot check chado schema api without chado.
      Please ensure chado is installed in the schema named "' . $this::$schemaName . '".');

    // Check all Chado 1.3 tables.
    $version = 1.3;
    $dataset = ['acquisition', 'acquisition_relationship', 'acquisitionprop',
    'analysis', 'analysis_cvterm', 'analysis_dbxref', 'analysis_pub',
    'analysis_relationship', 'analysisfeature', 'analysisfeatureprop',
    'analysisprop', 'arraydesign', 'arraydesignprop', 'assay',
    'assay_biomaterial', 'assay_project', 'assayprop', 'biomaterial',
    'biomaterial_dbxref', 'biomaterial_relationship', 'biomaterial_treatment',
    'biomaterialprop', 'cell_line', 'cell_line_cvterm', 'cell_line_cvtermprop',
    'cell_line_dbxref', 'cell_line_feature', 'cell_line_library', 'cell_line_pub',
    'cell_line_relationship', 'cell_line_synonym', 'cell_lineprop',
    'cell_lineprop_pub', 'chadoprop', 'channel', 'contact',
    'contact_relationship', 'contactprop', 'control', 'cv', 'cvprop', 'cvterm',
    'cvterm_dbxref', 'cvterm_relationship', 'cvtermpath', 'cvtermprop',
    'cvtermsynonym', 'db', 'dbprop', 'dbxref', 'dbxrefprop', 'eimage',
    'element', 'element_relationship', 'elementresult',
    'elementresult_relationship', 'environment', 'environment_cvterm',
    'expression', 'expression_cvterm', 'expression_cvtermprop',
    'expression_image', 'expression_pub', 'expressionprop', 'feature',
    'feature_contact', 'feature_cvterm', 'feature_cvterm_dbxref',
    'feature_cvterm_pub', 'feature_cvtermprop', 'feature_dbxref',
    'feature_expression', 'feature_expressionprop', 'feature_genotype',
    'feature_phenotype', 'feature_pub', 'feature_pubprop', 'feature_relationship',
    'feature_relationship_pub', 'feature_relationshipprop',
    'feature_relationshipprop_pub', 'feature_synonym', 'featureloc',
    'featureloc_pub', 'featuremap', 'featuremap_contact', 'featuremap_dbxref',
    'featuremap_organism', 'featuremap_pub', 'featuremapprop', 'featurepos',
    'featureposprop', 'featureprop', 'featureprop_pub', 'featurerange', 'genotype',
    'genotypeprop', 'library', 'library_contact', 'library_cvterm', 'library_dbxref',
    'library_expression', 'library_expressionprop', 'library_feature',
    'library_featureprop', 'library_pub', 'library_relationship',
    'library_relationship_pub', 'library_synonym', 'libraryprop', 'libraryprop_pub',
    'magedocumentation', 'mageml', 'nd_experiment',
    'nd_experiment_analysis', 'nd_experiment_contact', 'nd_experiment_dbxref',
    'nd_experiment_genotype', 'nd_experiment_phenotype', 'nd_experiment_project',
    'nd_experiment_protocol', 'nd_experiment_pub', 'nd_experiment_stock',
    'nd_experiment_stock_dbxref', 'nd_experiment_stockprop', 'nd_experimentprop',
    'nd_geolocation', 'nd_geolocationprop', 'nd_protocol', 'nd_protocol_reagent',
    'nd_protocolprop', 'nd_reagent', 'nd_reagent_relationship', 'nd_reagentprop',
    'organism', 'organism_cvterm', 'organism_cvtermprop', 'organism_dbxref',
    'organism_pub', 'organism_relationship', 'organismprop', 'organismprop_pub',
    'phendesc', 'phenotype', 'phenotype_comparison', 'phenotype_comparison_cvterm',
    'phenotype_cvterm', 'phenotypeprop', 'phenstatement', 'phylonode',
    'phylonode_dbxref', 'phylonode_organism', 'phylonode_pub', 'phylonode_relationship',
    'phylonodeprop', 'phylotree', 'phylotree_pub', 'phylotreeprop', 'project',
    'project_analysis', 'project_contact', 'project_dbxref', 'project_feature',
    'project_pub', 'project_relationship', 'project_stock', 'projectprop',
    'protocol', 'protocolparam', 'pub', 'pub_dbxref', 'pub_relationship',
    'pubauthor', 'pubauthor_contact', 'pubprop', 'quantification',
    'quantification_relationship', 'quantificationprop', 'stock', 'stock_cvterm',
    'stock_cvtermprop', 'stock_dbxref', 'stock_dbxrefprop', 'stock_feature',
    'stock_featuremap', 'stock_genotype', 'stock_library', 'stock_pub',
    'stock_relationship', 'stock_relationship_cvterm', 'stock_relationship_pub',
    'stockcollection', 'stockcollection_db', 'stockcollection_stock',
    'stockcollectionprop', 'stockprop', 'stockprop_pub', 'study', 'study_assay',
    'studydesign', 'studydesignprop', 'studyfactor', 'studyfactorvalue',
    'studyprop', 'studyprop_feature', 'synonym', 'tableinfo', 'treatment'];

    // Check: a schema is returned that matches what we expect.
    $chado_schema = new ChadoSchema($version, $this::$schemaName);
    foreach ($dataset as $table_name) {
      $table_schema = $chado_schema->getTableSchema($table_name);

      $this->assertNotEmpty(
        $table_schema,
        t('Returned schema for ":table" in chado v:version should not be empty.',
          [':table' => $table_name, ':version' => $version])
      );

      $this->assertArrayHasKey(
        'fields',
        $table_schema,
        t('The schema array for ":table" should have columns listed in an "fields" array',
          [':table' => $table_name])
      );

      // Instead of asserting these keys exist. Lets assert that if they do exist,
      // they match the expected format.

      if (isset($table_schema['primary key'])) {
        $this->assertTrue(is_array($table_schema['primary key']),
          t('The primary key of the Tripal Schema definition for ":table" must be an array.',
            [':table' => $table_name]));

      }

      $this->assertArrayHasKey(
        'foreign keys',
        $table_schema,
        t('The schema array for ":table" should have foreign keys listed in an "foreign keys" array',
          [':table' => $table_name])
      );
    }
  }

  /**
   * Tests ChadoSchema->getCustomTableSchema() method.
   *
   * @dataProvider knownCustomTableProvider
   *
   * @group api
   * @group chado
   * @group chado-schema
   *
   * NOTE: Currently being skipped because the custom tables functionality is
   *  not available yet.
   */
  public function testGetCustomTableSchema($table_name) {
    ChadoSchema::testMode(TRUE);

    $this->markTestSkipped('Custom Table functionality has not been upgraded yet.');

    // Check: a schema is returned that matches what we expect.
    $chado_schema = new ChadoSchema(NULL, $this::$schemaName);
    $table_schema = $chado_schema->getCustomTableSchema($table_name);

    $this->assertNotEmpty(
      $table_schema,
      t('Returned schema for ":table" in chado v:version should not be empty.',
        [':table' => $table_name, '!version' => $version])
    );

    $this->assertArrayHasKey(
      'fields',
      $table_schema,
      t('The schema array for ":table" should have columns listed in an "fields" array',
        ['!table' => $table_name])
    );

    // NOTE: Other then ensuring fields are set, we can't test further since all other
    // keys are technically optional and these arrays are set by admins.

  }

  /**
   * Tests ChadoSchema->getBaseTables() method.
   *
   * @dataProvider knownBaseTableProvider
   *
   * @group api
   * @group chado
   * @group chado-schema
   */
  public function testGetBaseTables($version, $known_tables) {
    ChadoSchema::testMode(TRUE);
    $connection = Database::getConnection();

    // Check that chado exists.
    $check_schema = "SELECT true FROM pg_namespace WHERE nspname = :schema";
    $exists = $connection->query($check_schema, [':schema' => $this::$schemaName])
      ->fetchField();
    $this->assertTrue($exists, 'Cannot check chado schema api without chado.
      Please ensure chado is installed in the schema named "' . $this::$schemaName . '".');

    // Check: Known base tables for a given version are returned.
    $chado_schema = new ChadoSchema($version, $this::$schemaName);
    $returned_tables = $chado_schema->getBaseTables();

    foreach ($known_tables as $table_name) {

      $found = FALSE;

      foreach ($returned_tables as $check_table) {

        if ($check_table == $table_name) {
          $found = TRUE;
        }
      }
      $this->assertTrue($found, "{$table_name} was not returned by getBaseTables for Chado v {$version}");
    }

  }

  /**********************************************
   * Data Providers:
   */

  /**
   * Data Provider: returns known tables specific to a given chado version.
   *
   * @return array
   */
  public function knownTableProvider() {
    // chado version, array of 3 tables specific to version.

    return [
      ['1.3', ['analysis_cvterm', 'dbprop', 'organism_pub']],
    ];
  }

  /**
   * Data Provider: returns known tables specific to a given chado version.
   *
   * @return array
   */
  public function knownBaseTableProvider() {
    // chado version, array of 3 tables specific to version.

    return [
      [
        '1.3',
        ['organism', 'feature', 'stock', 'project', 'analysis', 'phylotree'],
      ],
    ];
  }

  /**
   * Data Provider: returns known custom tables specific to a given chado
   * version.
   *
   * NOTE: These tables are provided by core Tripal so we should be able to
   *  depend on them. Also, for the same reason, chado version doesn't matter.
   *
   * @return array
   */
  public function knownCustomTableProvider() {

    return [
      ['library_feature_count'],
      ['organism_feature_count'],
      ['tripal_gff_temp'],
    ];
  }
}
