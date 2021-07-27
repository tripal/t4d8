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
        $table = "test_custom_table";
        chado_create_custom_table($table, $this::$schemaName, $skip_if_exists = TRUE, $mview_id = NULL, $redirect = FALSE);
        $results = chado_query("SELECT * FROM tripal_custom_tables WHERE table_name = :table_name", array(
            ':table_name' => $table
        ));
        $found_table = false;
        foreach ($result as $row) {
            if($row->table_name == $table) {
                $found_table = true;
            }
        }
        $this->assertNotFalse($found_table, 'Error, custom table was not created successfully');
      }
      else {
        // If test schema cannot be found, display php unit error
        $this->assertTrue(ChadoSchema::schemaExists($this::$schemaName), 
        'testchado schema could not be found to perform further tests');
      }
    }
}