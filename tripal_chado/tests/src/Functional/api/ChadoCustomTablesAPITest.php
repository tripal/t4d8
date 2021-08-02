<?php

namespace Drupal\Tests\tripal_chado;

use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use Drupal\Core\Database\Database;
use Drupal\tripal_chado\api\ChadoSchema;

/**
 * Testing the tripal_chado/api/tripal_chado.custom_tables.api.inc functions.
 *
 * @group Tripal
 * @group Tripal Chado
 * @group Tripal API
 */
class ChadoCustomTablesAPITest extends BrowserTestBase {

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
     * Tests chado.cv associated functions.
     *
     * @group tripal-chado
     * @group chado-cv
     */
    public function testcreatecustomtable() {
      if (ChadoSchema::schemaExists($this::$schemaName) == TRUE) {
        // Create a new custom table
        $table_name = 'test_custom_table';
        $table = array(
          'table' => 'test_custom_table',
          'fields' => array (
            'feature_id' => array (
              'type' => 'serial',
              'not null' => true,
            ),
            'organism_id' => array (
              'type' => 'int',
              'not null' => true,
            ),
            'uniquename' => array (
              'type' => 'text',
              'not null' => true,
            ),
            'type_name' => array (
              'type' => 'varchar',
              'length' => '1024',
              'not null' => true,
            ),
          ),
        );
        $customtable_result = chado_create_custom_table($table_name, $table,  FALSE, NULL, FALSE);
        // print_r($customtable_result);
        $results = chado_query("SELECT * FROM tripal_custom_tables", array(
            //':table_name' => $table_name
        ));
        $found_table = false;
        foreach ($results as $row) {
            // print_r($row);
            if($row->table_name == $table_name) {
                $found_table = true;
                break;
            }
        }
        $this->assertTrue($found_table, 'Error, custom table was not created successfully');
      }
      else {
        // If test schema cannot be found, display php unit error
        $this->assertTrue(ChadoSchema::schemaExists($this::$schemaName), 
        'testchado schema could not be found to perform further tests');
      }
    }
}