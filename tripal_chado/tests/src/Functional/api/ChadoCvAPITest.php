<?php

namespace Drupal\Tests\tripal_chado\Functional;

use Drupal\Core\Url;
use Drupal\Core\Database\Database;
use Drupal\tripal_chado\api\ChadoSchema;
use Drupal\Core\Test\FunctionalTestSetupTrait;

/**
 * Testing the tripal_chado/api/tripal_chado.schema.api.inc functions.
 *
 * @group Tripal
 * @group Tripal Chado
 * @group Tripal API
 */
class ChadoCvAPITest extends ChadoTestBrowserBase {

  /**
   * The name of the TripalDBX-managed test schema.
   * This is set in the setUp() function.
   * @var string
   */
  protected $schema_name;

  /**
   * {@inheritdoc}
   */
  protected function setUp() :void {

    parent::setUp();

    $this->assertIsObject($this->chado, "Chado test schema was not set-up properly.");

    $schema_name = $this->chado->getSchemaName();
    $this->assertNotEmpty($schema_name, "We were not able to retrieve the schema name.");

    $this->schema_name = $schema_name;
  }

  /**
   * Tests chado.cv associated functions.
   *
   * @group tripal-chado
   * @group chado-cv
   */
  public function testcv() {
    if (ChadoSchema::schemaExists($this->schema_name) == TRUE) {
      // INSERT.
      // chado_insert_cv().
      $cvval = [
        'name' => 'TD' . uniqid(),
        'definition' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit.',
      ];
      $return = chado_insert_cv($cvval['name'], $cvval['definition'], [], $this->schema_name);
      $this->assertNotFalse($return, 'chado_insert_cv failed unexpectedly.');
      $this->assertIsObject($return, 'Should be an updated cv object.');
      $this->assertObjectHasAttribute('cv_id', $return,
        "The returned object should have the primary key included.");
      $this->assertEquals($cvval['name'], $return->name,
        "The returned object should be the one we asked for.");
      // test the update part of chado_insert_cv().
      $returnagain = chado_insert_cv($cvval['name'], $cvval['definition'], [], $this->schema_name);
      $this->assertNotFalse($returnagain, 'chado_insert_cv failed unexpectedly.');
      $this->assertIsObject($returnagain, 'Should be an updated cv object.');
      $this->assertObjectHasAttribute('cv_id', $returnagain,
        "The returned object should have the primary key included.");
      $this->assertEquals($cvval['name'], $returnagain->name,
        "The returned object should be the one we asked for.");
      $this->assertEquals($return, $returnagain,
        "Both should be the same term!");

      // SELECT.
      // chado_get_cv().
      $selectval = [
        'name' => $cvval['name'],
      ];
      $return2 = chado_get_cv($selectval, [], $this->schema_name);
      $this->assertNotFalse($return2, 'chado_select_cv failed unexpectedly.');
      $this->assertIsObject($return2, 'Should be a cv object.');
      $this->assertEquals($cvval['name'], $return2->name,
        "The returned object should be the one we asked for.");
      // chado_get_cv_select_options().
      $returned_options = chado_get_cv_select_options($this->schema_name);
      $this->assertNotFalse($returned_options, 'chado_get_cv_select_options failed unexpectedly.');
      $this->assertIsArray($returned_options, 'Should be an array.');
      $this->assertNotEmpty($returned_options, "There should be at least one option.");;
      $this->assertArrayHasKey($return->cv_id, $returned_options,
        "The cv we added should be one of the options.");
    }
    else {
      // If test schema cannot be found, display php unit error
      $this->assertTrue(ChadoSchema::schemaExists($this->schema_name),
      'testchado schema could not be found to perform further tests');
    }
  }

  /**
   * Tests chado.cvterm associated functions.
   *
   * @group tripal-chado
   * @group chado-cv
   */
  public function testcvterm() {
    if (ChadoSchema::schemaExists($this->schema_name) == TRUE) {

      // INSERT.
      // chado_insert_cvterm().
      $cvval = [
        'name' => 'cvterm-test'.uniqid(),
        'definition' => 'none',
        ];
      $cv = chado_insert_cv($cvval['name'], $cvval['definition'], [], $this->schema_name);
      $cvtermval = [
        'cv_name' => $cv->name,
        'id' => 'chado_properties:version',
        'db_name' => 'null',
        'name' => 'cvterm-test'.uniqid(),
        'definition' => 'Lorem ipsum and I forget the rest.',
      ];
      $return = chado_insert_cvterm($cvtermval, [], $this->schema_name);
      $this->assertNotFalse($return, 'chado_insert_cvterm failed unexpectedly.');
      $this->assertIsObject($return, 'Should be an updated cvterm object.');
      $this->assertObjectHasAttribute('cvterm_id', $return,
        "The returned object should have the primary key included.");
      $this->assertEquals($cvtermval['name'], $return->name,
        "The returned object should be the one we asked for.");

      // check it is returned if it already exists.
      $returnagain = chado_insert_cvterm($cvtermval, [], $this->schema_name);
      $this->assertNotFalse($returnagain, 'chado_insert_cvterm failed unexpectedly.');
      $this->assertIsObject($returnagain, 'Should be an updated cvterm object.');
      $this->assertObjectHasAttribute('cvterm_id', $returnagain,
        "The returned object should have the primary key included.");
        $this->assertEquals($cvtermval['name'], $return->name,
          "The returned object should be the one we asked for.");
      $this->assertEquals($return, $returnagain,
        "Both should be the same term!");

      // chado_associate_cvterm().
      $org = ['genus' => 'Tripalus', 'species' => 'databasica'.uniqid()];
      $cvterm = ['name' => $return->name, 'cv_id' => $return->cv_id];
      $orgr = chado_insert_record('organism', $org, [], $this->schema_name);
      $return = chado_associate_cvterm(
        'organism',
        $orgr['organism_id'],
        $cvterm,
        [],
        $this->schema_name
      );
      $this->assertNotFalse($return, 'chado_associate_cvterm failed unexpectedly.');
      $this->assertIsObject($return, 'Should be the linking record.');

      // SELECT.
      // chado_get_cvterm().
      $return = chado_get_cvterm($cvterm, [], $this->schema_name);
      $this->assertNotFalse($return, 'chado_get_cvterm failed unexpectedly.');
      $this->assertIsObject($return, 'Should be a cvterm object.');
      $this->assertObjectHasAttribute('cvterm_id', $return,
        "The returned object should have the primary key included.");
      $this->assertEquals($cvtermval['name'], $return->name,
        "The returned object should be the one we asked for.");
    }
    else {
      // If test schema cannot be found, display php unit error
      $this->assertTrue(ChadoSchema::schemaExists($this->schema_name),
      'testchado schema could not be found to perform further tests');
    }
  }
}
