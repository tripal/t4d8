<?php

namespace Drupal\tripal_chado\Task;

use Drupal\tripal_chado\Task\ChadoTaskBase;
use Drupal\tripal_biodb\Exception\TaskException;
use Drupal\tripal_biodb\Exception\LockException;
use Drupal\tripal_biodb\Exception\ParameterException;
use Drupal\tripal\Entity\TripalEntityType;

/**
 * Chado preparer.
 *
 * Usage:
 * @code
 * // Where 'chado' is the name of the Chado schema to prepare.
 * $preparer = \Drupal::service('tripal_chado.preparer');
 * $preparer->setParameters([
 *   'output_schemas' => ['chado'],
 * ]);
 * if (!$preparer->performTask()) {
 *   // Display a message telling the user the task failed and details are in
 *   // the site logs.
 * }
 * @endcode
 */
class ChadoPreparer extends ChadoTaskBase {

  /**
   * Name of the task.
   */
  public const TASK_NAME = 'preparer';

  /**
   * Validate task parameters.
   *
   * Parameter array provided to the class constructor must include one output
   * schema and no input schema as shown:
   * ```
   * ['output_schemas' => ['schema_name'], ]
   * ```
   *
   * @throws \Drupal\tripal_biodb\Exception\ParameterException
   *   A descriptive exception is thrown in cas of invalid parameters.
   */
  public function validateParameters() :void {
    try {
      // Check input.
      if (!empty($this->parameters['input_schemas'])) {
        throw new ParameterException(
          "No input schema must be specified."
        );
      }

      // Check output.
      if (empty($this->parameters['output_schemas'])
          || (1 != count($this->parameters['output_schemas']))
      ) {
        throw new ParameterException(
          "Invalid number of output schemas. Only one output schema must be specified."
        );
      }

      $bio_tool = \Drupal::service('tripal_biodb.tool');
      $output_schema = $this->outputSchemas[0];

      // Note: schema names have already been validated through BioConnection.
      // Check if the target schema exists.
      if (!$output_schema->schema()->schemaExists()) {
        throw new ParameterException(
          'Output schema "'
          . $output_schema->getSchemaName()
          . '" does not exist.'
        );
      }
    }
    catch (\Exception $e) {
      // Log.
      $this->logger->error($e->getMessage());
      // Rethrow.
      throw $e;
    }
  }

  /**
   * Prepare a given chado schema by inserting minimal data.
   *
   * Task parameter array provided to the class constructor includes:
   * - 'input_schemas' array: no input schema
   * - 'output_schemas' array: one output Chado schema that must exist
   *   (required)
   *
   * Example:
   * ```
   * ['output_schemas' => ['chado_schema'], ]
   * ```
   *
   * @return bool
   *   TRUE if the task was performed with success and FALSE if the task was
   *   completed but without the expected success.
   *
   * @throws Drupal\tripal_biodb\Exception\TaskException
   *   Thrown when a major failure prevents the task from being performed.
   *
   * @throws \Drupal\tripal_biodb\Exception\ParameterException
   *   Thrown if parameters are incorrect.
   *
   * @throws Drupal\tripal_biodb\Exception\LockException
   *   Thrown when the locks can't be acquired.
   */
  public function performTask() :bool {
    // Task return status.
    $task_success = FALSE;

    // Validate parameters.
    $this->validateParameters();

    // Acquire locks.
    $success = $this->acquireTaskLocks();
    if (!$success) {
      throw new LockException("Unable to acquire all locks for task. See logs for details.");
    }

    try
    {
      $output_schema = $this->outputSchemas[0]->getSchemaName();

      $this->logger->notice("Creating Tripal Materialized Views and Custom Tables...");
      $chado_version = chado_get_version(FALSE, FALSE, $output_schema);
      if ($chado_version == '1.1') {
        $this->add_v1_1_custom_tables();
        $this->add_vx_x_custom_tables();
      }
      if ($chado_version == '1.2') {
        $this->add_v1_2_custom_tables();
        $this->add_vx_x_custom_tables();
      }
      if ($chado_version == '1.3') {
        $this->add_vx_x_custom_tables();
        $this->fix_v1_3_custom_tables();
      }
      
      $this->setProgress(0.1);
      $this->logger->notice("Loading ontologies...");
      $this->loadOntologies();
      
      $this->logger->notice('Populating materialized view cv_root_mview...');
      // TODO: populate mvies.
      
      $this->logger->notice("Making semantic connections for Chado tables/fields...");
      $this->populate_chado_semweb_table();
      
      $this->logger->notice("Map Chado Controlled vocabularies to Tripal Terms...");
      
      $this->logger->notice('Populating materialized view db2cv_mview...');
            

      $this->setProgress(0.5);
      $this->logger->notice("Creating default content types...");
      $this->contentTypes();

      $this->setProgress(1);
      $task_success = TRUE;

      // Release all locks.
      $this->releaseTaskLocks();

      // Cleanup state API.
      $this->state->delete(static::STATE_KEY_DATA_PREFIX . $this->id);
    }
    catch (\Exception $e) {
      $this->logger->error($e->getMessage());
      // Cleanup state API.
      $this->state->delete(static::STATE_KEY_DATA_PREFIX . $this->id);
      // Release all locks.
      $this->releaseTaskLocks();

      throw new TaskException(
        "Failed to complete schema integration task.\n"
        . $e->getMessage()
      );
    }

    return $task_success;
  }

  /**
   * Set progress value.
   *
   * @param float $value
   *   New progress value.
   */
  protected function setProgress(float $value) {
    $data = ['progress' => $value];
    $this->state->set(static::STATE_KEY_DATA_PREFIX . $this->id, $data);
  }

  /**
   * {@inheritdoc}
   */
  public function getProgress() :float {
    $data = $this->state->get(static::STATE_KEY_DATA_PREFIX . $this->id, []);

    if (empty($data)) {
      // No more data available. Assume process ended.
      $progress = 1;
    }
    else {
      $progress = $data['progress'];
    }
    return $progress;
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus() :string {
    $status = '';
    $progress = $this->getProgress();
    if (1 > $progress) {
      $status = 'Integration in progress.';
    }
    else {
      $status = 'Integration done.';
    }
    return $status;
  }
  
//   public function prepare() {
//     // $this->logger->info("Loading ontologies...");
//     // $this->loadOntologies();

//     // $this->logger->info("Creating default content types...");
//     // $this->contentTypes();

//     $this->logger->info("Loading feature prerequisites...");
//     $this->tripal_feature_install();

//     $this->logger->info("Loading Tripal Importer prerequisites...");
//     // Attempt to add the tripal_gff_temp table into chado
//     $this->logger->info("Add Tripal GFF Temp table...");
//     $this->tripal_chado_add_tripal_gff_temp_table();
//     // Attempt to add the tripal_gffprotein_temp table into chado
//     $this->logger->info("Add Tripal GFFPROTEIN Temp table...");
//     $this->tripal_chado_add_tripal_gffprotein_temp_table();
//     // Attempt to add the tripal_chado_add_tripal_gffcds_temp table into chado
//     $this->logger->info("Add Tripal GFFCDS Temp table...");
//     $this->tripal_chado_add_tripal_gffcds_temp_table();
//     // Attempt to add the tripal_chado_add_tripal_cv_obo table into chado
//     $this->logger->info("Add Tripal CV OBO table...");
//     $this->tripal_add_tripal_cv_obo_table();
//     // Attempt to add the mview table
//     $this->logger->info("Add Tripal MVIEWS table...");
//     $this->tripal_add_tripal_mviews_table();
//     // Attempt to add the chado_cvterm_mapping table
//     $this->logger->info("Add Tripal CVTERM mapping...");
//     $this->tripal_add_chado_cvterm_mapping();
//     // Attempt to add the tripal_cv_defaults
//     $this->logger->info("Add Tripal CV defaults...");
//     $this->tripal_add_chado_tripal_cv_defaults_table();
//     // Attempt to add the tripal_bundle table
//     $this->logger->info("Add Tripal bundle schema...");
//     $this->tripal_add_tripal_bundle_schema();

//     // Attempt to add prerequisite ontology data (seems to be needed by the OBO
//     // importers) for example
//     $this->logger->info("Load ontologies required for Tripal Importers to function properly...");
//     $this->tripal_chado_load_ontologies();

//     $this->logger->info("Preparation complete.");
//   }


//   /**
//    * Implements hook_install().
//    *
//    * @ingroup tripal_legacy_feature
//    */
//   function tripal_feature_install() {

//     // Note: the feature_property OBO that came with Chado v1.2 should not
//     // be automatically installed.  Some of the terms are duplicates of
//     // others in better maintained vocabularies.  New Tripal sites should
//     // use those.
//     // $obo_path = '{tripal_feature}/files/feature_property.obo';
//     // $obo_id = tripal_insert_obo('Chado Feature Properties', $obo_path);
//     // tripal_submit_obo_job(array('obo_id' => $obo_id));

//     // Add the vocabularies used by the feature module.
//     $this->tripal_feature_add_cvs();
    
//     $output_schema = $this->outputSchemas[0]->getSchemaName();

//     // Set the default vocabularies.
//     tripal_set_default_cv('feature', 'type_id', 'sequence', FALSE, $output_schema);
//     tripal_set_default_cv('featureprop', 'type_id', 'feature_property', FALSE, $output_schema);
//     tripal_set_default_cv('feature_relationship', 'type_id', 'feature_relationship', FALSE, $output_schema);
//   }  

//   /**
//    * Add cvs related to publications
//    *
//    * @ingroup tripal_pub
//    */
//   function tripal_feature_add_cvs() {
    
//     $output_schema = $this->outputSchemas[0]->getSchemaName();
    

//     // Add cv for relationship types
//     chado_insert_cv(
//       'feature_relationship',
//       'Contains types of relationships between features.',
//       [],
//       $output_schema
//     );

//     // The feature_property CV may already exists. It comes with Chado, but
//     // we need to  add it just in case it doesn't get added before the feature
//     // module is installed. But as of Tripal v3.0 the Chado version of this
//     // vocabulary is no longer loaded by default.
//     chado_insert_cv(
//       'feature_property',
//       'Stores properties about features',
//       [],
//       $output_schema
//     );

//     // the feature type vocabulary should be the sequence ontology, and even though
//     // this ontology should get loaded we will create it here just so that we can
//     // set the default vocabulary for the feature.type_id field
//     chado_insert_cv(
//       'sequence',
//       'The Sequence Ontology',
//       [],
//       $output_schema
//     );
//   }  


  /**
   * The base table for TripalEntity entities.
   *
   * This table contains a list of Biological Data Types.
   * For the example above (5 genes and 10 mRNAs), there would only be two records in
   * this table one for "gene" and another for "mRNA".
   */
  function tripal_add_tripal_bundle_schema() {
    $tableExists = \Drupal::database()->schema()->tableExists('tripal_bundle');
    if(!$tableExists) {    
      $schema = array(
        'description' => 'Stores information about defined tripal data types.',
        'fields' => array(
          'id' => array(
            'type' => 'serial',
            'not null' => TRUE,
            'description' => 'Primary Key: Unique numeric ID.',
          ),
          'type' => array(
            'description' => 'The type of entity (e.g. TripalEntity).',
            'type' => 'varchar',
            'length' => 64,
            'not null' => TRUE,
            'default' => '',
          ),
          'term_id' => array(
            'description' => 'The term_id for the type of entity. This term_id corresponds to a TripalTerm record.',
            'type' => 'int',
            'not null' => TRUE,
          ),
          'name' => array(
            'description' => 'The name of the bundle. This should be an official vocabulary ID (e.g. SO, RO, GO) followed by an underscore and the term accession.',
            'type' => 'varchar',
            'length' => 1024,
            'not null' => TRUE,
            'default' => '',
          ),
          'label' => array(
            'description' => 'The human-readable name of this bundle.',
            'type' => 'varchar',
            'length' => 255,
            'not null' => TRUE,
            'default' => '',
          ),
        ),
        'indexes' => array(
          'name' => array('name'),
          'term_id' => array('term_id'),
          'label' => array('label'),
        ),
        'primary key' => array('id'),
        'unique keys' => array(
          'name' => array('name'),
        ),
      );
      \Drupal::database()->schema()->createTable('tripal_bundle', $schema);
    }
    else {
      print "tripal_bundle table already exists... bypassing...\n";
    }  
  }





  /**
   * * Table definition for the tripal_cv_defaults table
   * @param unknown $schema
   */
  function tripal_add_chado_tripal_cv_defaults_table() {
    $tableExists = \Drupal::database()->schema()->tableExists('tripal_cv_defaults');
    if(!$tableExists) {  
      $schema = array(
        'fields' => array(
          'cv_default_id' => array(
            'type' => 'serial',
            'unsigned' => TRUE,
            'not null' => TRUE
          ),
          'table_name' => array(
            'type' => 'varchar',
            'length' => 128,
            'not null' => TRUE,
          ),
          'field_name' => array(
            'type' => 'varchar',
            'length' => 128,
            'not null' => TRUE,
          ),
          'cv_id' => array(
            'type' => 'int',
            'not null' => TRUE,
          )
        ),
        'indexes' => array(
          'tripal_cv_defaults_idx1' => array('table_name', 'field_name'),
        ),
        'unique keys' => array(
          'tripal_cv_defaults_unq1' => array('table_name', 'field_name', 'cv_id'),
        ),
        'primary key' => array('cv_default_id')
      );
      \Drupal::database()->schema()->createTable('tripal_cv_defaults', $schema);
      // chado_create_custom_table('tripal_mviews', $schema, TRUE, NULL, FALSE);
    }
    else {
      print "tripal_cv_defaults table already exists... bypassing...\n";
    } 
  }

  
  public function tripal_add_chado_cvterm_mapping() {
    $tableExists = \Drupal::database()->schema()->tableExists('chado_cvterm_mapping');
    if(!$tableExists) {    
      $schema = array (
        'fields' => array (
          'mapping_id' => array(
            'type' => 'serial',
            'not null' => TRUE
          ),
          'cvterm_id' => array (
            'type' => 'int',
            'not null' => TRUE
          ),
          'chado_table' => array (
            'type' => 'varchar',
            'length' => 128,
            'not null' => TRUE
          ),
          'chado_field' => array (
            'type' => 'varchar',
            'length' => 128,
            'not null' => FALSE
          ),
        ),
        'primary key' => array (
          0 => 'mapping_id'
        ),
        'unique key' => array(
          'cvterm_id',
        ),
        'indexes' => array(
          'tripal_cvterm2table_idx1' => array('cvterm_id'),
          'tripal_cvterm2table_idx2' => array('chado_table'),
          'tripal_cvterm2table_idx3' => array('chado_table', 'chado_field'),
        ),
      ); 
      \Drupal::database()->schema()->createTable('chado_cvterm_mapping', $schema);
      // chado_create_custom_table('tripal_mviews', $schema, TRUE, NULL, FALSE);
    }
    else {
      print "chado_cvterm_mapping table already exists... bypassing...\n";
    }       
  }

  public function tripal_add_tripal_mviews_table() {
    $tableExists = \Drupal::database()->schema()->tableExists('tripal_mviews');
    if(!$tableExists) {
      $schema = array(
        'fields' => array(
          'mview_id' => array(
            'type' => 'serial',
            'unsigned' => TRUE,
            'not null' => TRUE
          ),
          'name' => array(
            'type' => 'varchar',
            'length' => 255,
            'not null' => TRUE
          ),
          'modulename' => array(
            'type' => 'varchar',
            'length' => 50,
            'not null' => TRUE,
            'description' => 'The module name that provides the callback for this job'
          ),
          'mv_table' => array(
            'type' => 'varchar',
            'length' => 128,
            'not null' => FALSE
          ),
          'mv_specs' => array(
            'type' => 'text',
            'size' => 'normal',
            'not null' => FALSE
          ),
          'mv_schema' => array(
            'type' => 'text',
            'size' => 'normal',
            'not null' => FALSE
          ),
          'indexed' => array(
            'type' => 'text',
            'size' => 'normal',
            'not null' => FALSE
          ),
          'query' => array(
            'type' => 'text',
            'size' => 'normal',
            'not null' => TRUE
          ),
          'special_index' => array(
            'type' => 'text',
            'size' => 'normal',
            'not null' => FALSE
          ),
          'last_update' => array(
            'type' => 'int',
            'not null' => FALSE,
            'description' => 'UNIX integer time'
          ),
          'status'        => array(
            'type' => 'text',
            'size' => 'normal',
            'not null' => FALSE
          ),
          'comment' => array(
            'type' => 'text',
            'size' => 'normal',
            'not null' => FALSE
          ),
        ),
        'indexes' => array(
          'mview_id' => array('mview_id')
        ),
        'unique keys' => array(
          'mv_table' => array('mv_table'),
          'mv_name' => array('name'),
        ),
        'primary key' => array('mview_id'),
      );
      \Drupal::database()->schema()->createTable('tripal_mviews', $schema);
      // chado_create_custom_table('tripal_mviews', $schema, TRUE, NULL, FALSE);
    }
    else {
      print "tripal_mviews table already exists... bypassing...\n";
    }
  }  


  public function tripal_add_tripal_cv_obo_table() {
    $tableExists = \Drupal::database()->schema()->tableExists('tripal_cv_obo');
    if(!$tableExists) {    
      $schema = [
        // 'table' => 'tripal_cv_obo',
        'fields' => [
          'obo_id' => [
            'type' => 'serial',
            'unsigned' => TRUE,
            'not null' => TRUE
          ],
          'name' => [
            'type' => 'varchar',
            'length' => 255
          ],
          'path'  => [
            'type' => 'varchar',
            'length' => 1024
          ],
        ],
        'indexes' => [
          'tripal_cv_obo_idx1' => ['obo_id'],
        ],
        'primary key' => ['obo_id'],
      ];
      \Drupal::database()->schema()->createTable('tripal_cv_obo', $schema);
    }
    // chado_create_custom_table('tripal_cv_obo', $schema, TRUE, NULL, FALSE);
  }

  public function tripal_chado_add_tripal_gff_temp_table() {
    $tableExists = chado_table_exists('tripal_gff_temp');
    if(!$tableExists) {
      $schema = [
        // 'table' => 'tripal_gff_temp',
        'fields' => [
          'feature_id' => [
            'type' => 'int',
            'not null' => TRUE,
          ],
          'organism_id' => [
            'type' => 'int',
            'not null' => TRUE,
          ],
          'uniquename' => [
            'type' => 'text',
            'not null' => TRUE,
          ],
          'type_name' => [
            'type' => 'varchar',
            'length' => '1024',
            'not null' => TRUE,
          ],
        ],
        'indexes' => [
          'tripal_gff_temp_idx0' => ['feature_id'],
          'tripal_gff_temp_idx0' => ['organism_id'],
          'tripal_gff_temp_idx1' => ['uniquename'],
        ],
        'unique keys' => [
          'tripal_gff_temp_uq0' => ['feature_id'],
          'tripal_gff_temp_uq1' => ['uniquename', 'organism_id', 'type_name'],
        ],
      ];
      chado_create_custom_table('tripal_gff_temp', $schema, TRUE, NULL, FALSE);
    }
    else {
      print "tripal_gff_temp chado table already exists... bypassing...\n";
    }
  }

  public function tripal_chado_add_tripal_gffprotein_temp_table() {
    $tableExists = chado_table_exists('tripal_gffprotein_temp');
    if(!$tableExists) {    
      $schema = [
        // 'table' => 'tripal_gffprotein_temp',
        'fields' => [
          'feature_id' => [
            'type' => 'int',
            'not null' => TRUE,
          ],
          'parent_id' => [
            'type' => 'int',
            'not null' => TRUE,
          ],
          'fmin' => [
            'type' => 'int',
            'not null' => TRUE,
          ],
          'fmax' => [
            'type' => 'int',
            'not null' => TRUE,
          ],
        ],
        'indexes' => [
          'tripal_gff_temp_idx0' => ['feature_id'],
          'tripal_gff_temp_idx0' => ['parent_id'],
        ],
        'unique keys' => [
          'tripal_gff_temp_uq0' => ['feature_id'],
        ],
      ];
      chado_create_custom_table('tripal_gffprotein_temp', $schema, TRUE, NULL, FALSE);
    }
    else {
      print "tripal_gffprotein_temp chado table already exists... bypassing...\n";
    }
  }
  
  public function tripal_chado_add_tripal_gffcds_temp_table() {
    $tableExists = chado_table_exists('tripal_gffcds_temp');
    if(!$tableExists) {    
      $schema = [
        // 'table' => 'tripal_gffcds_temp',
        'fields' => [
          'feature_id' => [
            'type' => 'int',
            'not null' => TRUE,
          ],
          'parent_id' => [
            'type' => 'int',
            'not null' => TRUE,
          ],
          'phase' => [
            'type' => 'int',
            'not null' => FALSE,
          ],
          'strand' => [
            'type' => 'int',
            'not null' => TRUE,
          ],
          'fmin' => [
            'type' => 'int',
            'not null' => TRUE,
          ],
          'fmax' => [
            'type' => 'int',
            'not null' => TRUE,
          ],
        ],
        'indexes' => [
          'tripal_gff_temp_idx0' => ['feature_id'],
          'tripal_gff_temp_idx0' => ['parent_id'],
        ],
      ];
      chado_create_custom_table('tripal_gffcds_temp', $schema, TRUE, NULL, FALSE);
    }
    else {
      print "tripal_gffcds_temp chado table already exists... bypassing...\n";
    }
  }


  /**
   *
   */
  function tripal_chado_load_ontologies() {

    // Before we can load ontologies we need a few terms that unfortunately
    // don't get added until later. We'll add them now so the loader works.
    chado_insert_db([
      'name' => 'NCIT',
      'description' => 'NCI Thesaurus OBO Edition.',
      'url' => 'http://purl.obolibrary.org/obo/ncit.owl',
      'urlprefix' => ' http://purl.obolibrary.org/obo/{db}_{accession}',
    ]);
    chado_insert_cv(
      'ncit',
      'The NCIt OBO Edition project aims to increase integration of the NCIt with OBO Library ontologies. NCIt is a reference terminology that includes broad coverage of the cancer domain, including cancer related diseases, findings and abnormalities. NCIt OBO Edition releases should be considered experimental.'
    );

    $term = chado_insert_cvterm([
      'id' => 'NCIT:C25693',
      'name' => 'Subgroup',
      'cv_name' => 'ncit',
      'definition' => 'A subdivision of a larger group with members often exhibiting similar characteristics. [ NCI ]',
    ]);


    // Add the rdfs:comment vocabulary.
    chado_insert_db([
      'name' => 'rdfs',
      'description' => 'Resource Description Framework Schema',
      'url' => 'https://www.w3.org/TR/rdf-schema/',
      'urlprefix' => 'http://www.w3.org/2000/01/rdf-schema#{accession}',
    ]);
    chado_insert_cv(
      'rdfs',
      'Resource Description Framework Schema'
    );
    $name = chado_insert_cvterm([
      'id' => 'rdfs:comment',
      'name' => 'comment',
      'cv_name' => 'rdfs',
      'definition' => 'A human-readable description of a resource\'s name.',
    ]);

    // Insert commonly used ontologies into the tables.
    $ontologies = [
      [
        'name' => 'Relationship Ontology (legacy)',
        'path' => '{tripal_chado}/files/legacy_ro.obo',
        'auto_load' => FALSE,
        'cv_name' => 'ro',
        'db_name' => 'RO',
      ],
      [
        'name' => 'Gene Ontology',
        'path' => 'http://purl.obolibrary.org/obo/go.obo',
        'auto_load' => FALSE,
        'cv_name' => 'cellualar_component',
        'db_name' => 'GO',
      ],
      [
        'name' => 'Taxonomic Rank',
        'path' => 'http://purl.obolibrary.org/obo/taxrank.obo',
        'auto_load' => TRUE,
        'cv_name' => 'taxonomic_rank',
        'db_name' => 'TAXRANK',
      ],
      [
        'name' => 'Tripal Contact',
        'path' => '{tripal_chado}/files/tcontact.obo',
        'auto_load' => TRUE,
        'cv_name' => 'tripal_contact',
        'db_name' => 'TContact',
      ],
      [
        'name' => 'Tripal Publication',
        'path' => '{tripal_chado}/files/tpub.obo',
        'auto_load' => TRUE,
        'cv_name' => 'tripal_pub',
        'db_name' => 'TPUB',
      ],
      [
        'name' => 'Sequence Ontology',
        'path' => 'http://purl.obolibrary.org/obo/so.obo',
        'auto_load' => TRUE,
        'cv_name' => 'sequence',
        'db_name' => 'SO',
      ],

    ];

    for ($i = 0; $i < count($ontologies); $i++) {
      $obo_id = chado_insert_obo($ontologies[$i]['name'], $ontologies[$i]['path']);
    }    
    /*
    module_load_include('inc', 'tripal_chado', 'includes/TripalImporter/OBOImporter');
    for ($i = 0; $i < count($ontologies); $i++) {
      $obo_id = chado_insert_obo($ontologies[$i]['name'], $ontologies[$i]['path']);
      if ($ontologies[$i]['auto_load'] == TRUE) {
        // Only load ontologies that are not already in the cv table.
        $cv = chado_get_cv(['name' => $ontologies[$i]['cv_name']]);
        $db = chado_get_db(['name' => $ontologies[$i]['db_name']]);
        if (!$cv or !$db) {
          print "Loading ontology: " . $ontologies[$i]['name'] . " ($obo_id)...\n";
          $obo_importer = new OBOImporter();
          $obo_importer->create(['obo_id' => $obo_id]);
          $obo_importer->run();
          $obo_importer->postRun();
        }
        else {
          print "Ontology already loaded (skipping): " . $ontologies[$i]['name'] . "...\n";
        }
      }
    }
    */
  }


  /**
   * Loads ontologies necessary for creation of default Tripal content types.
   */
  protected function loadOntologies() {

    /*
     This currently cannot be implementated as the vocabulary API is being
     re-done. As such, this method is a placeholder.

     See https://github.com/tripal/tripal/blob/7.x-3.x/tripal_chado/includes/setup/tripal_chado.setup.inc
     for the Tripal 3 implementation of this method.

     Vocabularies to be added individually:
     - NCIT: NCI Thesaurus OBO Edition
     - rdfs: Resource Description Framework Schema

     Terms to be added individually:
     - Subgroup (NCIT:C25693)
     - rdfs:comment

     Ontologies to be imported by the OBO Loader:
     - Legacy Relationship Ontology: {tripal_chado}/files/legacy_ro.obo
     - Gene Ontology: http://purl.obolibrary.org/obo/go.obo
     - Taxonomic Rank: http://purl.obolibrary.org/obo/taxrank.obo
     - Tripal Contact: {tripal_chado}/files/tcontact.obo
     - Tripal Publication: {tripal_chado}/files/tpub.obo
     - Sequence Ontology: http://purl.obolibrary.org/obo/so.obo
     - Crop Ontology Germplasm: https://raw.githubusercontent.com/UofS-Pulse-Binfo/kp_entities/master/ontologies/CO_010.obo
     - EDAM Ontology: http://edamontology.org/EDAM.obo

     NOTE: Regarding CO_010 (crop ontology of germplasm), for some reason this
     has been removed from the original crop ontology website. As such, I've linked
     here to a file which loads and is correct. We use 4 terms from this ontology
     for our content types so we may want to consider alternatives.
     One such alternative may be MCPD: http://agroportal.lirmm.fr/ontologies/CO_020

    */

    $this->logger->warning("\tWaiting on completion of the Vocabulary API and Data Loaders.");
  }

  /**
   * Creates default content types.
   */
  protected function contentTypes() {
    $this->generalContentTypes();
    $this->genomicContentTypes();
    $this->geneticContentTypes();
    $this->germplasmContentTypes();
    $this->expressionContentTypes();
  }

  /**
   * Helper: Create a given set of types.
   *
   * @param $types
   *   An array of types to be created. The key is used to link the type to
   *   it's term and the value is an array of details to be passed to the
   *   create() method for Tripal Entity Types. Some keys should be:
   *    - id: the integer id for this type; this will go away since it
   *        should be automatic.
   *    - name: the machine name for this type. It should be bio_data_[id]
   *        where [id] matches the integer id above. Also should be automatic.
   *    - label: a human-readable label for the content type.
   *    - category: a grouping string to categorize content types in the UI.
   *    - help_text: a single sentence describing the content type -usually
   *        the default is the term definition.
   * @param $terms
   *   An array of terms which must already exist where the key maps to a
   *   content type in the $types array. The value for each item is an array of
   *   details to be passed to the term creation API. Some keys should be:
   *    - accession: the unique identifier for the term (i.e. 2945)
   *    - vocabulary:
   *       - namespace: The name of the vocabulary (i.e. EDAM).
   *       - idspace: the id space of the term (i.e. operation).
   */
  protected function createGivenContentTypes($types, $terms) {
    foreach($terms as $key => $term_details) {
      $type_details = $types[$key];

      $this->logger->notice("  -- Creating " . $type_details['label'] . " (" . $type_details['name'] . ")...");

      // TODO: Create the term once the API is upgraded.
      // $term = \Drupal::service('tripal.tripalTerm.manager')->getTerms($term_details);

      // TODO: Set the term in the type details.
      if (is_object($term)) {
        // $type_details['term_id'] = $term->getID();
        if (!array_key_exists($type_details, 'help_text')) {
          // $type_details['help_text'] = $term->getDefinition();
        }
      }
      else {
        $this->logger->warning("\tNo term attached -waiting on API update.");
      }

      // Check if the type already exists.
      // TODO: use term instead of label once it's available.
      $filter = ['label' => $type_details['label'] ];
      $exists = \Drupal::entityTypeManager()
        ->getStorage('tripal_entity_type')
        ->loadByProperties($filter);

      // Create the Type.
      if (empty($exists)) {
        $tripal_type = TripalEntityType::create($type_details);
        if (is_object($tripal_type)) {
          $tripal_type->save();
          $this->logger->notice("\tSaved successfully.");
        }
        else {
          $this->logger->error("\tCreation Failed! Details provided were: " . print_r($type_details));
        }
      }
      else {
        $this->logger->notice("\tSkipping as the content type already exists.");
      }
    }
  }

  /**
   * Creates the "General" category of content types.
   *
   * @code
   $terms[''] =[
     'accession' => '',
     'vocabulary' => [
       'idspace' => '',
     ],
   ];
   $types['']= [
     'id' => ,
     'name' => '',
     'label' => '',
     'category' => 'General',
   ];
   * @endcode
   */
  protected function generalContentTypes() {

    // The 'Organism' entity type. This uses the obi:organism term.
    $terms['organism'] =[
      'accession' => '0100026',
      'vocabulary' => [
        'idspace' => 'OBI',
      ],
    ];
    $types['organism']= [
      'id' => 1,
      'name' => 'bio_data_1',
      'label' => 'Organism',
      'category' => 'General',
    ];

    // The 'Analysis' entity type. This uses the EDAM:analysis term.
    $terms['analysis'] = [
      'accession' => '2945',
      'vocabulary' => [
        'namespace' => 'EDAM',
        'idspace' => 'operation',
      ],
    ];
    $types['analysis'] = [
      'id' => 2,
      'name' => 'bio_data_2',
      'label' => 'Analysis',
      'category' => 'General',
    ];

    // The 'Project' entity type. bio_data_3
    $terms['project'] =[
      'accession' => 'C47885',
      'vocabulary' => [
        'idspace' => 'NCIT',
      ],
    ];
    $types['project']= [
      'id' => 3,
      'name' => 'bio_data_3',
      'label' => 'Project',
      'category' => 'General',
    ];

    // The 'Study' entity type. bio_data_4
    $terms['study'] =[
      'accession' => '001066',
      'vocabulary' => [
        'idspace' => 'SIO',
      ],
    ];
    $types['study']= [
      'id' => 4,
      'name' => 'bio_data_4',
      'label' => 'Study',
      'category' => 'General',
    ];

    // The 'Contact' entity type. bio_data_5
    $terms['contact'] =[
      'accession' => 'contact',
      'vocabulary' => [
        'idspace' => 'local',
      ],
    ];
    $types['contact']= [
      'id' => 5,
      'name' => 'bio_data_5',
      'label' => 'Contact',
      'category' => 'General',
    ];

    // The 'Publication' entity type. bio_data_6
    $terms['publication'] =[
      'accession' => '0000002',
      'vocabulary' => [
        'idspace' => 'TPUB',
      ],
    ];
    $types['publication']= [
      'id' => 6,
      'name' => 'bio_data_6',
      'label' => 'Publication',
      'category' => 'General',
    ];

    // The 'Protocol' entity type. bio_data_7
    $terms['protocol'] =[
      'accession' => '00101',
      'vocabulary' => [
        'idspace' => 'sep',
      ],
    ];
    $types['protocol']= [
      'id' => 7,
      'name' => 'bio_data_7',
      'label' => 'Protocol',
      'category' => 'General',
    ];

    $this->createGivenContentTypes($types, $terms);
  }

  /**
   * Creates the "Genomic" category of content types.
   *
   * @code
   $terms[''] =[
     'accession' => '',
     'vocabulary' => [
       'idspace' => '',
     ],
   ];
   $types['']= [
     'id' => ,
     'name' => '',
     'label' => '',
     'category' => 'Genomic',
   ];
   * @endcode
   */
  protected function genomicContentTypes() {

    // The 'Gene' entity type. This uses the sequence:gene term.
    $terms['gene'] = [
      'accession' => '0000704',
      'vocabulary' => [
        'namespace' => 'sequence',
        'idspace' => 'SO',
      ],
    ];
    $types['gene'] = [
      'id' => 8,
      'name' => 'bio_data_8',
      'label' => 'Gene',
      'category' => 'Genomic',
    ];

    // the 'mRNA' entity type. bio_data_9
    $terms['mRNA'] =[
      'accession' => '0000234',
      'vocabulary' => [
        'idspace' => 'SO',
      ],
    ];
    $types['mRNA']= [
      'id' => 9,
      'name' => 'bio_data_9',
      'label' => 'mRNA',
      'category' => 'Genomic',
    ];

    // The 'Phylogenetic tree' entity type. bio_data_10
    $terms['phylo'] =[
      'accession' => '0872',
      'vocabulary' => [
        'idspace' => 'data',
      ],
    ];
    $types['phylo']= [
      'id' => 10,
      'name' => 'bio_data_10',
      'label' => 'Phylogenetic Tree',
      'category' => 'Genomic',
    ];

    // The 'Physical Map' entity type. bio_data_11
    $terms['map'] =[
      'accession' => '1280',
      'vocabulary' => [
        'idspace' => 'data',
      ],
    ];
    $types['map']= [
      'id' => 11,
      'name' => 'bio_data_11',
      'label' => 'Physical Map',
      'category' => 'Genomic',
    ];

    // The 'DNA Library' entity type. bio_data_12
    $terms['library'] =[
      'accession' => 'C16223',
      'vocabulary' => [
        'idspace' => 'NCIT',
      ],
    ];
    $types['library']= [
      'id' => 12,
      'name' => 'bio_data_12',
      'label' => 'DNA Library',
      'category' => 'Genomic',
    ];

    // The 'Genome Assembly' entity type. bio_data_13
    $terms['assembly'] =[
      'accession' => '0525',
      'vocabulary' => [
        'idspace' => 'operation',
      ],
    ];
    $types['assembly']= [
      'id' => 13,
      'name' => 'bio_data_13',
      'label' => 'Genome Assembly',
      'category' => 'Genomic',
    ];

    // The 'Genome Annotation' entity type. bio_data_14
    $terms['annotation'] =[
      'accession' => '0362',
      'vocabulary' => [
        'idspace' => 'operation',
      ],
    ];
    $types['annotation']= [
      'id' => 14,
      'name' => 'bio_data_14',
      'label' => 'Genome Assembly',
      'category' => 'Genomic',
    ];

    // The 'Genome Project' entity type. bio_data_15
    $terms['genomeproject'] =[
      'accession' => 'Genome Project',
      'vocabulary' => [
        'idspace' => 'local',
      ],
    ];
    $types['genomeproject']= [
      'id' => 15,
      'name' => 'bio_data_15',
      'label' => 'Genome Project',
      'category' => 'Genomic',
    ];

    $this->createGivenContentTypes($types, $terms);
  }

  /**
   * Creates the "Genetic" category of content types.
   *
   * @code
   $terms[''] =[
     'accession' => '',
     'vocabulary' => [
       'idspace' => '',
     ],
   ];
   $types['']= [
     'id' => ,
     'name' => '',
     'label' => '',
     'category' => 'Genetic',
   ];
   * @endcode
   */
  protected function geneticContentTypes() {

    // The 'Genetic Map' entity type. bio_data_16
    $terms['map'] =[
      'accession' => '1278',
      'vocabulary' => [
        'idspace' => 'data',
      ],
    ];
    $types['map']= [
      'id' => 16,
      'name' => 'bio_data_16',
      'label' => 'Genetic Map',
      'category' => 'Genetic',
    ];

    // The 'QTL' entity type. bio_data_17
    $terms['qtl'] =[
      'accession' => '0000771',
      'vocabulary' => [
        'idspace' => 'SO',
      ],
    ];
    $types['qtl']= [
      'id' => 17,
      'name' => 'bio_data_17',
      'label' => 'QTL',
      'category' => 'Genetic',
    ];

    // The 'Sequence Variant' entity type. bio_data_18
    $terms['variant'] =[
      'accession' => '0001060',
      'vocabulary' => [
        'idspace' => 'SO',
      ],
    ];
    $types['variant']= [
      'id' => 18,
      'name' => 'bio_data_18',
      'label' => 'Sequence Variant',
      'category' => 'Genetic',
    ];

    // The 'Genetic Marker' entity type. bio_data_19
    $terms['marker'] =[
      'accession' => '0001645',
      'vocabulary' => [
        'idspace' => 'SO',
      ],
    ];
    $types['marker']= [
      'id' => 19,
      'name' => 'bio_data_19',
      'label' => 'Genetic Marker',
      'category' => 'Genetic',
    ];

    // The 'Heritable Phenotypic Marker' entity type. bio_data_20
    $terms['hpn'] =[
      'accession' => '0001500',
      'vocabulary' => [
        'idspace' => 'SO',
      ],
    ];
    $types['hpn']= [
      'id' => 20,
      'name' => 'bio_data_20',
      'label' => 'Heritable Phenotypic Marker',
      'category' => 'Genetic',
    ];

    $this->createGivenContentTypes($types, $terms);
  }

  /**
   * Creates the "Germplasm" category of content types.
   *
   * @code
   $terms[''] =[
     'accession' => '',
     'vocabulary' => [
       'idspace' => '',
     ],
   ];
   $types['']= [
     'id' => ,
     'name' => '',
     'label' => '',
     'category' => 'Germplasm',
   ];
   * @endcode
   */
  protected function germplasmContentTypes() {

    // The 'Phenotypic Trait' entity type. bio_data_28
    $terms['trait'] =[
      'accession' => 'C85496',
      'vocabulary' => [
        'idspace' => 'NCIT',
      ],
    ];
    $types['trait']= [
      'id' => 28,
      'name' => 'bio_data_28',
      'label' => 'Phenotypic Trait',
      'category' => 'Germplasm',
    ];

    // The 'Germplasm Accession' entity type. bio_data_21
    $terms['accession'] = [
      'accession' => '0000044',
      'vocabulary' => [
        'namespace' => 'germplasm_ontology',
        'idspace' => 'CO_010',
      ],
    ];
    $types['accession'] = [
      'id' => 21,
      'name' => 'bio_data_21',
      'label' => 'Germplasm Accession',
      'category' => 'Germplasm',
    ];

    // The 'Breeding Cross' entity type. bio_data_22
    $terms['cross'] =[
      'accession' => '0000255',
      'vocabulary' => [
        'idspace' => 'CO_010',
      ],
    ];
    $types['cross']= [
      'id' => 22,
      'name' => 'bio_data_22',
      'label' => 'Breeding Cross',
      'category' => 'Germplasm',
    ];

    // The 'Germplasm Variety' entity type. bio_data_23
    $terms['variety'] =[
      'accession' => '0000029',
      'vocabulary' => [
        'idspace' => 'CO_010',
      ],
    ];
    $types['variety']= [
      'id' => 23,
      'name' => 'bio_data_23',
      'label' => 'Germplasm Variety',
      'category' => 'Germplasm',
    ];

    // The 'Recombinant Inbred Line' entity type. bio_data_24
    $terms['ril'] =[
      'accession' => '0000162',
      'vocabulary' => [
        'idspace' => 'CO_010',
      ],
    ];
    $types['ril']= [
      'id' => 24,
      'name' => 'bio_data_24',
      'label' => 'Recombinant Inbred Line',
      'category' => 'Germplasm',
    ];

    $this->createGivenContentTypes($types, $terms);
  }

  /**
   * Creates the "Expression" category of content types.
   *
   * @code
   $terms[''] =[
     'accession' => '',
     'vocabulary' => [
       'idspace' => '',
     ],
   ];
   $types['']= [
     'id' => ,
     'name' => '',
     'label' => '',
     'category' => 'Expression',
   ];
   * @endcode
   */
  protected function expressionContentTypes() {

    // The 'biological sample' entity type. bio_data_25
    $terms['sample'] =[
      'accession' => '00195',
      'vocabulary' => [
        'idspace' => 'sep',
      ],
    ];
    $types['sample']= [
      'id' => 25,
      'name' => 'bio_data_25',
      'label' => 'Biological Sample',
      'category' => 'Expression',
    ];

    // The 'Assay' entity type. bio_data_26
    $terms['assay'] =[
      'accession' => '0000070',
      'vocabulary' => [
        'idspace' => 'OBI',
      ],
    ];
    $types['assay']= [
      'id' => 26,
      'name' => 'bio_data_26',
      'label' => 'Assay',
      'category' => 'Expression',
    ];

    // The 'Array Design' entity type. bio_data_27
    $terms['design'] =[
      'accession' => '0000269',
      'vocabulary' => [
        'idspace' => 'EFO',
      ],
    ];
    $types['design']= [
      'id' => 27,
      'name' => 'bio_data_27',
      'label' => 'Array Design',
      'category' => 'Expression',
    ];

    $this->createGivenContentTypes($types, $terms);
  }
  /**
   * For Chado v1.1 Tripal provides some new custom tables.
   *
   * For Chado v1.2 or greater these tables are not needed as they are part of the
   * schema update.
   */
  protected function add_v1_1_custom_tables() {
    module_load_include('inc', 'tripal_chado', 'includes/setup/tripal_chado.chado_v1_1');
    tripal_chado_add_analysisfeatureprop_table();
  }
  
  /**
   * For Chado v1.2 Tripal provides some new custom tables.
   *
   * For Chado v1.3 these tables are not needed as they are part of the
   * schema update.
   */
  protected function add_v1_2_custom_tables() {
    module_load_include('inc', 'tripal_chado', 'includes/setup/tripal_chado.chado_v1.2');
    tripal_chado_add_contactprop_table();
    tripal_chado_add_featuremap_dbxref_table();
    tripal_chado_add_featuremapprop_table();
    tripal_chado_add_featureposprop_table();
    tripal_chado_add_pubauthor_contact_table();
  }
  
  /**
   * Add custom tables for any version of Chado.
   *
   * These are tables that Chado uses to manage the site (i.e. temporary
   * loading tables) and not for primary data storage.
   */
  protected function add_vx_x_custom_tables() {
    module_load_include('inc', 'tripal_chado', 'includes/setup/tripal_chado.chado_vx_x');
    
    // Add in custom tables.
    tripal_chado_add_tripal_gff_temp_table();
    tripal_chado_add_tripal_gffcds_temp_table();
    tripal_chado_add_tripal_gffprotein_temp_table();
    tripal_chado_add_tripal_obo_temp_table();
    
    // Add in materialized views.
    tripal_chado_add_organism_stock_count_mview();
    tripal_chado_add_library_feature_count_mview();
    tripal_chado_add_organism_feature_count_mview();
    tripal_chado_add_analysis_organism_mview();
    tripal_chado_add_cv_root_mview_mview();
    tripal_chado_add_db2cv_mview_mview();
    
  }
  
  /**
   * Many of the custom tables created for Chado v1.2 are now in Chado v1.3.
   *
   * These tables need not be tracked by Tripal anymore as custom tables and
   * in some cases the Chado version has different columns so we need to
   * adjust them.
   */
  protected function fix_v1_3_custom_tables() {
    
    
    // Update the featuremap_dbxref table by adding an is_current field.
    if (!chado_column_exists('featuremap_dbxref', 'is_current')) {
      chado_query("ALTER TABLE {featuremap_dbxref} ADD COLUMN is_current boolean DEFAULT true NOT NULL;");
    }
    
    // Remove the previously managed custom tables from the
    // tripal_custom_tables table.
    db_delete('tripal_custom_tables')
    ->condition('table_name', [
      'analysisfeatureprop',
      'featuremap_dbxref',
      'contactprop',
      'featuremapprop',
      'featureposprop',
      'pubauthor_contact',
    ])
    ->execute();
  }
  
  protected function populate_chado_semweb_table() {
    // Add in all tables and fields into the chado_semweb table.
    $chado_tables = chado_get_table_names(TRUE);
    foreach ($chado_tables as $chado_table) {
      chado_add_semweb_table($chado_table);
    }
    
    // TODO: should this code be in the tripal_chado module? Some of these terms
    // are used solely by web services (e.g. rdfs:label) and are not used to
    // map chado terms to vocabularies.
    
    // Perhaps we should have an API for working with terms where these can be
    // inserted.
    
    // Now set defaults!
    tripal_chado_populate_vocab_CO_010();
    tripal_chado_populate_vocab_DC();
    tripal_chado_populate_vocab_EDAM();
    tripal_chado_populate_vocab_ERO();
    tripal_chado_populate_vocab_EFO();
    tripal_chado_populate_vocab_FOAF();
    tripal_chado_populate_vocab_HYDRA();
    tripal_chado_populate_vocab_IAO();
    tripal_chado_populate_vocab_LOCAL();
    tripal_chado_populate_vocab_NCIT();
    tripal_chado_populate_vocab_NCBITAXON();
    tripal_chado_populate_vocab_OBCS();
    tripal_chado_populate_vocab_OBI();
    tripal_chado_populate_vocab_OGI();
    tripal_chado_populate_vocab_RDFS();
    tripal_chado_populate_vocab_SBO();
    tripal_chado_populate_vocab_SCHEMA();
    tripal_chado_populate_vocab_SEP();
    tripal_chado_populate_vocab_SIO();
    tripal_chado_populate_vocab_SO();
    tripal_chado_populate_vocab_SWO();
    tripal_chado_populate_vocab_TAXRANK();
    tripal_chado_populate_vocab_TCONTACT();
    tripal_chado_populate_vocab_TPUB();
    tripal_chado_populate_vocab_UO();
  }
  
  /**
   * Adds the friend of a friend database and terms.
   */
  function tripal_chado_populate_vocab_FOAF() {
    
    chado_insert_db([
      'name' => 'foaf',
      'description' => 'Friend of a Friend',
      'url' => 'http://www.foaf-project.org/',
      'urlprefix' => 'http://xmlns.com/foaf/spec/#',
    ]);
    chado_insert_cv(
        'foaf',
        'Friend of a Friend. A dictionary of people-related terms that can be used in structured data).'
        );
  }
  
  /**
   * Adds the Hydra vocabulary
   */
  function tripal_chado_populate_vocab_HYDRA() {
    
    // For the HydraConsole to work with webservices the URL must be set as
    // http://www.w3.org/ns/hydra/core
    chado_insert_db([
      'name' => 'hydra',
      'description' => 'A Vocabulary for Hypermedia-Driven Web APIs',
      'url' => 'http://www.w3.org/ns/hydra/core',
      'urlprefix' => 'http://www.w3.org/ns/hydra/core#{accession}',
    ]);
    chado_insert_cv(
        'hydra',
        'A Vocabulary for Hypermedia-Driven Web APIs.'
        );
    
    $name = chado_insert_cvterm([
      'id' => 'hydra:Collection',
      'name' => 'Collection',
      'cv_name' => 'hydra',
      'definition' => 'A collection holding references to a number of related resources.',
    ]);
    $name = chado_insert_cvterm([
      'id' => 'hydra:member',
      'name' => 'member',
      'cv_name' => 'hydra',
      'definition' => 'A member of the collection',
    ]);
    $name = chado_insert_cvterm([
      'id' => 'hydra:description',
      'name' => 'description',
      'cv_name' => 'hydra',
      'definition' => 'A description.',
    ]);
    $name = chado_insert_cvterm([
      'id' => 'hydra:totalItems',
      'name' => 'totalItems',
      'cv_name' => 'hydra',
      'definition' => 'The total number of items referenced by a collection.',
    ]);
    $name = chado_insert_cvterm([
      'id' => 'hydra:title',
      'name' => 'title',
      'cv_name' => 'hydra',
      'definition' => 'A title, often used along with a description.',
    ]);
    $name = chado_insert_cvterm([
      'id' => 'hydra:PartialCollectionView',
      'name' => 'PartialCollectionView',
      'cv_name' => 'hydra',
      'definition' => 'A PartialCollectionView describes a partial view of a Collection. Multiple PartialCollectionViews can be connected with the the next/previous properties to allow a client to retrieve all members of the collection.',
    ]);
  }
  
  /**
   * Adds the RDFS database and terms.
   */
  function tripal_chado_populate_vocab_RDFS() {
    
    chado_insert_db([
      'name' => 'rdf',
      'description' => 'Resource Description Framework',
      'url' => 'http://www.w3.org/1999/02/22-rdf-syntax-ns',
      'urlprefix' => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
    ]);
    chado_insert_cv(
        'rdf',
        'Resource Description Framework'
        );
    
    chado_insert_db([
      'name' => 'rdfs',
      'description' => 'Resource Description Framework Schema',
      'url' => 'https://www.w3.org/TR/rdf-schema/',
      'urlprefix' => 'http://www.w3.org/2000/01/rdf-schema#{accession}',
    ]);
    chado_insert_cv(
        'rdfs',
        'Resource Description Framework Schema'
        );
    
    $name = chado_insert_cvterm([
      'id' => 'rdfs:type',
      'name' => 'type',
      'cv_name' => 'rdfs',
      'definition' => 'The type of resource.',
    ]);
    $name = chado_insert_cvterm([
      'id' => 'rdfs:label',
      'name' => 'label',
      'cv_name' => 'rdfs',
      'definition' => 'A human-readable version of a resource\'s name.',
    ]);
    $name = chado_insert_cvterm([
      'id' => 'rdfs:comment',
      'name' => 'comment',
      'cv_name' => 'rdfs',
      'definition' => 'A human-readable description of a resource\'s name.',
    ]);
  }
  
  /**
   * Adds the Schema.org database and terms.
   */
  function tripal_chado_populate_vocab_SCHEMA() {
    chado_insert_db([
      'name' => 'schema',
      'description' => 'Schema.org.',
      'url' => 'https://schema.org/',
      'urlprefix' => 'https://schema.org/{accession}',
    ]);
    chado_insert_cv(
        'schema',
        'Schema.org. Schema.org is sponsored by Google, Microsoft, Yahoo and Yandex. The vocabularies are developed by an open community process.'
        );
    
    $term = chado_insert_cvterm([
      'id' => 'schema:name',
      'name' => 'name',
      'cv_name' => 'schema',
      'definition' => 'The name of the item.',
    ]);
    chado_associate_semweb_term(NULL, 'name', $term);
    chado_associate_semweb_term('analysis', 'sourcename', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'schema:alternateName',
      'name' => 'alternateName',
      'cv_name' => 'schema',
      'definition' => 'An alias for the item.',
    ]);
    chado_associate_semweb_term(NULL, 'synonym_id', $term);
    chado_associate_semweb_term('cvtermsynonym', 'synonym', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'schema:comment',
      'name' => 'comment',
      'cv_name' => 'schema',
      'definition' => 'Comments, typically from users.',
    ]);
    chado_associate_semweb_term(NULL, 'comment', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'schema:description',
      'name' => 'description',
      'cv_name' => 'schema',
      'definition' => 'A description of the item.',
    ]);
    chado_associate_semweb_term(NULL, 'description', $term);
    chado_associate_semweb_term('organism', 'comment', $term);
    chado_associate_semweb_term('protocol', 'protocoldescription', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'schema:publication',
      'name' => 'publication',
      'cv_name' => 'schema',
      'definition' => 'A publication event associated with the item.',
    ]);
    chado_associate_semweb_term(NULL, 'pub_id', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'schema:url',
      'name' => 'url',
      'cv_name' => 'schema',
      'definition' => 'URL of the item.',
    ]);
    chado_associate_semweb_term('db', 'url', $term);
    
    // Typically the type_id field is used for distinguishing between records
    // but in the case that it isn't then we need to associate a term with it
    // An entity already has a type so if that type is not dicated by the
    // type_id field then what is in the type_id should therefore be an
    // "additionalType".  Therefore we need to add and map this term to all
    // of the appropriate type_id fields.
    $term = chado_insert_cvterm([
      'id' => 'schema:additionalType',
      'name' => 'additionalType',
      'cv_name' => 'schema',
      'definition' => 'An additional type for the item, typically used for adding more specific types from external vocabularies in microdata syntax. This is a relationship between something and a class that the thing is in.',
    ]);
    $tables = chado_get_table_names(TRUE);
    foreach ($tables as $table) {
      $schema = chado_get_schema($table);
      // The type_id for the organism is infraspecific type, so don't make
      // the association for that type.
      if ($table == 'organism') {
        continue;
      }
      if (in_array("type_id", array_keys($schema['fields']))) {
        chado_associate_semweb_term($table, 'type_id', $term);
      }
    }
    
    $term = chado_insert_cvterm([
      'id' => 'schema:ItemPage',
      'name' => 'ItemPage',
      'cv_name' => 'schema',
      'definition' => 'A page devoted to a single item, such as a particular product or hotel.',
    ]);
    
  }
  
  /**
   * Adds the Sample processing and separation techniques database and terms.
   */
  function tripal_chado_populate_vocab_SEP() {
    chado_insert_db([
      'name' => 'sep',
      'description' => 'Sample processing and separation techniques.',
      'url' => 'http://psidev.info/index.php?q=node/312',
      'urlprefix' => 'http://purl.obolibrary.org/obo/{db}_{accession}',
    ]);
    chado_insert_cv('sep', 'A structured controlled vocabulary for the annotation of sample processing and separation techniques in scientific experiments.');
    $term = chado_insert_cvterm([
      'id' => 'sep:00195',
      'name' => 'biological sample',
      'cv_name' => 'sep',
      'definition' => 'A biological sample analysed by a particular technology.',
    ]);
    chado_associate_semweb_term(NULL, 'biomaterial_id', $term);
    
    $term = tripal_insert_cvterm([
      'id' => 'sep:00101',
      'name' => 'protocol',
      'cv_name' => 'sep',
      'definition' => 'A protocol is a process which is a parameterizable description of a process.',
    ]);
    chado_associate_semweb_term(NULL, 'protocol_id', $term);
    chado_associate_semweb_term(NULL, 'nd_protocol_id', $term);
  }
  
  /**
   * Adds the SemanticScience database and terms.
   */
  function tripal_chado_populate_vocab_SIO() {
    chado_insert_db([
      'name' => 'SIO',
      'description' => 'Semanticscience Integrated Ontology.',
      'url' => 'http://sio.semanticscience.org/',
      'urlprefix' => 'http://semanticscience.org/resource/{db}_{accession}',
    ]);
    chado_insert_cv('SIO', ' The Semanticscience Integrated Ontology (SIO) provides a simple, integrated ontology of types and relations for rich description of objects, processes and their attributes.');
    
    $term = chado_insert_cvterm([
      'id' => 'SIO:000493',
      'name' => 'clause',
      'cv_name' => 'SIO',
      'definition' => 'A clause consists of a subject and a predicate.',
    ]);
    
    $term = chado_insert_cvterm([
      'id' => 'SIO:000631',
      'name' => 'references',
      'cv_name' => 'SIO',
      'definition' => 'references is a relation between one entity and the entity that it makes reference to by name, but is not described by it.',
    ]);
    
    $term = chado_insert_cvterm([
      'id' => 'SIO:000056',
      'name' => 'position',
      'cv_name' => 'SIO',
      'definition' => 'A measurement of a spatial location relative to a frame of reference or other objects.',
    ]);
    chado_associate_semweb_term('featurepos', 'mappos', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'SIO:001166',
      'name' => 'annotation',
      'cv_name' => 'SIO',
      'definition' => 'An annotation is a written explanatory or critical description, or other in-context information (e.g., pattern, motif, link), that has been associated with data or other types of information.',
    ]);
    chado_associate_semweb_term('feature_cvterm', 'cvterm_id', $term);
    chado_associate_semweb_term('analysis_cvterm', 'cvterm_id', $term);
    chado_associate_semweb_term('cell_line_cvterm', 'cvterm_id', $term);
    chado_associate_semweb_term('environment_cvterm', 'cvterm_id', $term);
    chado_associate_semweb_term('expression_cvterm', 'cvterm_id', $term);
    chado_associate_semweb_term('library_cvterm', 'cvterm_id', $term);
    chado_associate_semweb_term('organism_cvterm', 'cvterm_id', $term);
    chado_associate_semweb_term('phenotype_cvterm', 'cvterm_id', $term);
    chado_associate_semweb_term('stock_cvterm', 'cvterm_id', $term);
    chado_associate_semweb_term('stock_relationship_cvterm', 'cvterm_id', $term);
    
    
    $term = chado_insert_cvterm([
      'id' => 'SIO:000281',
      'name' => 'negation',
      'cv_name' => 'SIO',
      'definition' => 'NOT is a logical operator in that has the value true if its operand is false.',
    ]);
    chado_associate_semweb_term('feature_cvterm', 'is_not', $term);
    chado_associate_semweb_term('analysis_cvterm', 'is_not', $term);
    chado_associate_semweb_term('organism_cvterm', 'is_not', $term);
    chado_associate_semweb_term('stock_cvterm', 'is_not', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'SIO:001080',
      'name' => 'vocabulary',
      'cv_name' => 'SIO',
      'definition' => 'A vocabulary is a collection of terms.',
    ]);
    chado_associate_semweb_term('cvterm', 'cv_id', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'SIO:001323',
      'name' => 'email address',
      'cv_name' => 'SIO',
      'definition' => 'an email address is an identifier to send mail to particular electronic mailbox.',
    ]);
    
    $term = chado_insert_cvterm([
      'id' => 'SIO:001007',
      'name' => 'assay',
      'cv_name' => 'SIO',
      'definition' => 'An assay is an investigative (analytic) procedure in ' .
      'laboratory medicine, pharmacology, environmental biology, and ' .
      'molecular biology for qualitatively assessing or quantitatively ' .
      'measuring the presence or amount or the functional activity of a ' .
      'target entity (the analyte) which can be a drug or biochemical ' .
      'substance or a cell in an organism or organic sample.',
    ]);
    chado_associate_semweb_term(NULL, 'assay_id', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'SIO:010054',
      'name' => 'cell line',
      'cv_name' => 'SIO',
      'definition' => 'A cell line is a collection of genetically identifical cells.',
    ]);
    chado_associate_semweb_term(NULL, 'cell_line_id', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'SIO:001066',
      'name' => 'study',
      'cv_name' => 'SIO',
      'definition' => 'A study is a process that realizes the steps of a study design.',
    ]);
    chado_associate_semweb_term(NULL, 'study_id', $term);
    
  }
  
  /**
   * Adds the details for the SO vocab and db.
   */
  function tripal_chado_populate_vocab_SO() {
    chado_insert_db([
      'name' => 'SO',
      'description' => 'The sequence ontology.',
      'url' => 'http://www.sequenceontology.org/',
      'urlprefix' => 'http://www.sequenceontology.org/browser/current_svn/term/{db}:{accession}',
    ]);
    chado_insert_cv('sequence', 'The sequence ontology.');
    
    $term = chado_get_cvterm([
      'cv_id' => ['name' => 'sequence'],
      'name' => 'sequence_feature',
    ]);
    chado_associate_semweb_term(NULL, 'feature_id', $term);
  }
  
  
  /**
   * Adds the Crop Ontology terms.
   */
  public function populate_vocab_CO_010($schema_name) {
    chado_insert_db([
      'name' => 'CO_010',
      'description' => 'Crop Germplasm Ontology',
      'url' => 'http://www.cropontology.org/get-ontology/CO_010',
      'urlprefix' => 'http://www.cropontology.org/terms/CO_010:{accession}',
    ], $schema_name);
    chado_insert_cv(
      'germplasm_ontology',
      'GCP germplasm ontology',
      $schema_name
    );
    $term = chado_insert_cvterm([
      'id' => 'CO_010:0000044',
      'name' => 'accession',
      'cv_name' => 'germplasm_ontology',
      'definition' => '',
    ]);
    $term = chado_insert_cvterm([
      'id' => 'CO_010:0000255',
      'name' => 'generated germplasm',
      'cv_name' => 'germplasm_ontology',
      'definition' => '',
    ]);
    $term = chado_insert_cvterm([
      'id' => 'CO_010:0000029',
      'name' => 'cultivar',
      'cv_name' => 'germplasm_ontology',
      'definition' => '',
    ]);
    $term = chado_insert_cvterm([
      'id' => 'CO_010:0000162',
      'name' => '414 inbred line',
      'cv_name' => 'germplasm_ontology',
      'definition' => '',
    ]);
  }
  
  /**
   * Adds the DC database.
   */
  public function populate_vocab_DC() {
    chado_insert_db([
      'name' => 'dc',
      'description' => 'DCMI Metadata Terms.',
      'url' => 'http://purl.org/dc/dcmitype/',
      'urlprefix' => 'http://purl.org/dc/terms/{accession}',
    ]);
    chado_insert_cv(
        'dc',
        'DCMI Metadata Terms.'
        );
    $term = chado_insert_cvterm([
      'id' => 'dc:Service',
      'name' => 'Service',
      'cv_name' => 'dc',
      'definition' => 'A system that provides one or more functions.',
    ]);
  }
  
  /**
   * Adds the EDAM database and terms.
   */
  public function populate_vocab_EDAM() {
    
    chado_insert_db([
      'name' => 'data',
      'description' => 'Bioinformatics operations, data types, formats, identifiers and topics.',
      'url' => 'http://edamontology.org/page',
      'urlprefix' => 'http://edamontology.org/{db}_{accession}',
    ]);
    chado_insert_db([
      'name' => 'format',
      'description' => 'A defined way or layout of representing and structuring data in a computer file, blob, string, message, or elsewhere. The main focus in EDAM lies on formats as means of structuring data exchanged between different tools or resources. ',
      'url' => 'http://edamontology.org/page',
      'urlprefix' => 'http://edamontology.org/{db}_{accession}',
    ]);
    chado_insert_db([
      'name' => 'operation',
      'description' => 'A function that processes a set of inputs and results in a set of outputs, or associates arguments (inputs) with values (outputs). Special cases are: a) An operation that consumes no input (has no input arguments).',
      'url' => 'http://edamontology.org/page',
      'urlprefix' => 'http://edamontology.org/{db}_{accession}',
    ]);
    chado_insert_db([
      'name' => 'topic',
      'description' => 'A category denoting a rather broad domain or field of interest, of study, application, work, data, or technology. Topics have no clearly defined borders between each other.',
      'url' => 'http://edamontology.org/page',
      'urlprefix' => 'http://edamontology.org/{db}_{accession}',
    ]);
    chado_insert_db([
      'name' => 'EDAM',
      'description' => 'Bioinformatics operations, data types, formats, identifiers and topics.',
      'url' => 'http://edamontology.org/page',
      'urlprefix' => 'http://edamontology.org/{db}_{accession}',
    ]);
    chado_insert_cv(
        'EDAM',
        'EDAM is an ontology of well established, familiar concepts that are ' .
        'prevalent within bioinformatics, including types of data and data ' .
        'identifiers, data formats, operations and topics. EDAM is a simple ' .
        'ontology - essentially a set of terms with synonyms and definitions - ' .
        'organised into an intuitive hierarchy for convenient use by curators, ' .
        'software developers and end-users. EDAM is suitable for large-scale ' .
        'semantic annotations and categorization of diverse bioinformatics ' .
        'resources. EDAM is also suitable for diverse application including ' .
        'for example within workbenches and workflow-management systems, ' .
        'software distributions, and resource registries.'
        );
    
    $term = chado_insert_cvterm([
      'id' => 'data:1249',
      'name' => 'Sequence length',
      'cv_name' => 'EDAM',
      'definition' => 'The size (length) of a sequence, subsequence or region in a sequence, or range(s) of lengths.',
    ]);
    chado_associate_semweb_term('feature', 'seqlen', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'data:2190',
      'name' => 'Sequence checksum',
      'cv_name' => 'EDAM',
      'definition' => 'A fixed-size datum calculated (by using a hash function) for a molecular sequence, typically for purposes of error detection or indexing.',
    ]);
    chado_associate_semweb_term(NULL, 'md5checksum', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'data:2091',
      'name' => 'Accession',
      'cv_name' => 'EDAM',
      'definition' => 'A persistent (stable) and unique identifier, typically identifying an object (entry) from a database.',
    ]);
    chado_associate_semweb_term(NULL, 'dbxref_id', $term);
    chado_associate_semweb_term('dbxref', 'accession', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'data:2044',
      'name' => 'Sequence',
      'cv_name' => 'EDAM',
      'definition' => 'One or more molecular sequences, possibly with associated annotation.',
    ]);
    chado_associate_semweb_term('feature', 'residues', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'data:0849',
      'name' => 'Sequence record',
      'cv_name' => 'EDAM',
      'definition' => 'A molecular sequence and associated metadata.',
    ]);
    
    $term = chado_insert_cvterm([
      'id' => 'data:0842',
      'name' => 'Identifier',
      'cv_name' => 'EDAM',
      'definition' => 'A text token, number or something else which identifies an entity, but which may not be persistent (stable) or unique (the same identifier may identify multiple things).',
    ]);
    chado_associate_semweb_term(NULL, 'uniquename', $term);
    chado_associate_semweb_term('assay', 'arrayidentifier', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'data:2976',
      'name' => 'Protein sequence',
      'cv_name' => 'EDAM',
      'definition' => 'One or more protein sequences, possibly with associated annotation.',
    ]);
    
    $term = chado_insert_cvterm([
      'id' => 'data:2968',
      'name' => 'Image',
      'cv_name' => 'EDAM',
      'definition' => 'Biological or biomedical data has been rendered into an image, typically for display on screen.',
    ]);
    chado_associate_semweb_term(NULL, 'eimage_id', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'data:1274',
      'name' => 'Map',
      'cv_name' => 'EDAM',
      'definition' => 'A map of (typically one) DNA sequence annotated with positional or non-positional features.',
    ]);
    chado_associate_semweb_term(NULL, 'featuremap_id', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'data:1278',
      'name' => 'Genetic map',
      'cv_name' => 'EDAM',
      'definition' => 'A map showing the relative positions of genetic markers in a nucleic acid sequence, based on estimation of non-physical distance such as recombination frequencies.',
    ]);
    chado_associate_semweb_term('featuremap', 'featuremap_id', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'data:1280',
      'name' => 'Physical map',
      'cv_name' => 'EDAM',
      'definition' => 'A map of DNA (linear or circular) annotated with physical features or landmarks such as restriction sites, cloned DNA fragments, genes or genetic markers, along with the physical distances between them. Distance in a physical map is measured in base pairs. A physical map might be ordered relative to a reference map (typically a genetic map) in the process of genome sequencing.',
    ]);
    chado_associate_semweb_term('featuremap', 'featuremap_id', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'data:2012',
      'name' => 'Sequence coordinates',
      'cv_name' => 'EDAM',
      'definition' => 'A position in a map (for example a genetic map), either a single position (point) or a region / interval.',
    ]);
    
    $term = chado_insert_cvterm([
      'id' => 'data:1056',
      'name' => 'Database name',
      'cv_name' => 'EDAM',
      'definition' => 'The name of a biological or bioinformatics database.',
    ]);
    
    $term = chado_insert_cvterm([
      'id' => 'data:1048',
      'name' => 'Database ID',
      'cv_name' => 'EDAM',
      'definition' => 'An identifier of a biological or bioinformatics database.',
    ]);
    chado_associate_semweb_term('db', 'name', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'data:1047',
      'name' => 'URI',
      'cv_name' => 'EDAM',
      'definition' => 'The name of a biological or bioinformatics database.',
    ]);
    chado_associate_semweb_term('analysis', 'sourceuri', $term);
    chado_associate_semweb_term(NULL, 'uri', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'data:2336',
      'name' => 'Translation phase specification',
      'cv_name' => 'EDAM',
      'definition' => 'Phase for translation of DNA (0, 1 or 2) relative to a fragment of the coding sequence.',
    ]);
    chado_associate_semweb_term('featureloc', 'phase', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'data:0853',
      'name' => 'DNA sense specification',
      'cv_name' => 'EDAM',
      'definition' => 'The strand of a DNA sequence (forward or reverse).',
    ]);
    chado_associate_semweb_term('featureloc', 'strand', $term);
    $term = chado_insert_cvterm([
      'id' => 'data:3002',
      'name' => 'Annotation track',
      'cv_name' => 'EDAM',
      'definition' => 'Annotation of one particular positional feature on a ' .
      'biomolecular (typically genome) sequence, suitable for import and ' .
      'display in a genome browser. Synonym: Sequence annotation track.',
    ]);
    chado_associate_semweb_term('featureloc', 'srcfeature_id', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'operation:2945',
      'name' => 'Analysis',
      'cv_name' => 'EDAM',
      'definition' => 'Apply analytical methods to existing data of a specific type.',
    ]);
    chado_associate_semweb_term(NULL, 'analysis_id', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'data:0872',
      'name' => 'Phylogenetic tree',
      'cv_name' => 'EDAM',
      'definition' => 'The raw data (not just an image) from which a phylogenetic tree is directly generated or plotted, such as topology, lengths (in time or in expected amounts of variance) and a confidence interval for each length.',
    ]);
    chado_associate_semweb_term(NULL, 'phylotree_id', $term);
    $term = chado_insert_cvterm([
      'id' => 'data:3272',
      'name' => 'Species tree',
      'cv_name' => 'EDAM',
      'definition' => 'A phylogenetic tree that reflects phylogeny of the taxa from which the characters (used in calculating the tree) were sampled.',
    ]);
    $term = chado_insert_cvterm([
      'id' => 'data:3271',
      'name' => 'Gene tree',
      'cv_name' => 'EDAM',
      'definition' => 'A phylogenetic tree that is an estimate of the character\'s phylogeny.',
    ]);
    $term = chado_insert_cvterm([
      'id' => 'operation:0567',
      'name' => 'Phylogenetic tree visualisation',
      'cv_name' => 'EDAM',
      'definition' => 'A phylogenetic tree that is an estimate of the character\'s phylogeny.',
    ]);
    $term = chado_insert_cvterm([
      'id' => 'operation:0564',
      'name' => 'Sequence visualisation',
      'cv_name' => 'EDAM',
      'definition' => 'Visualise, format or render a molecular sequence or sequences such as a sequence alignment, possibly with sequence features or properties shown.',
    ]);
    
    $term = chado_insert_cvterm([
      'id' => 'operation:0525',
      'name' => 'genome assembly',
      'cv_name' => 'EDAM',
      'definition' => '',
    ]);
    
    $term = chado_insert_cvterm([
      'id' => 'operation:0362',
      'name' => 'Genome annotation ',
      'cv_name' => 'EDAM',
      'definition' => '',
    ]);
    
  }
  
  /**
   * Adds the Experimental Factor Ontology and terms.
   */
  function tripal_chado_populate_vocab_EFO() {
    chado_insert_db([
      'name' => 'EFO',
      'description' => 'Experimental Factor Ontology',
      'url' => 'http://www.ebi.ac.uk/efo/efo.owl',
      'urlprefix' => 'http://www.ebi.ac.uk/efo/{db}_{accession}',
    ]);
    chado_insert_cv(
        'efo',
        'The Experimental Factor Ontology (EFO) provides a systematic description of many experimental variables available in EBI databases, and for external projects such as the NHGRI GWAS catalogue. It combines parts of several biological ontologies, such as anatomy, disease and chemical compounds. The scope of EFO is to support the annotation, analysis and visualization of data handled by many groups at the EBI and as the core ontology for OpenTargets.org'
        );
    
    $term = chado_insert_cvterm([
      'id' => 'EFO:0000548',
      'name' => 'instrument',
      'cv_name' => 'efo',
      'definition' => 'An instrument is a device which provides a mechanical or electronic function.',
    ]);
    chado_associate_semweb_term('protocol', 'hardwaredescription', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'EFO:0000269',
      'name' => 'array design',
      'cv_name' => 'efo',
      'definition' => 'An instrument design which describes the design of the array.',
    ]);
    chado_associate_semweb_term('assay', 'arraydesign_id', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'EFO:0005522',
      'name' => 'substrate type',
      'cv_name' => 'efo',
      'definition' => 'Controlled terms for descriptors of types of array substrates.',
    ]);
    chado_associate_semweb_term('arraydesign', 'substratetype_id', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'EFO:0001728',
      'name' => 'array manufacturer',
      'cv_name' => 'efo',
      'definition' => '',
    ]);
    chado_associate_semweb_term('arraydesign', 'manufacturer_id', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'EFO:0000269',
      'name' => 'array design',
      'cv_name' => 'efo',
      'definition' => 'An instrument design which describes the design of the array.',
    ]);
    chado_associate_semweb_term('element', 'arraydesign_id', $term);
  }
  
  /**
   * Adds the Eagle-i Resource Ontology database and terms.
   */
  function tripal_chado_populate_vocab_ERO() {
    chado_insert_db([
      'name' => 'ERO',
      'description' => 'The Eagle-I Research Resource Ontology',
      'url' => 'http://purl.bioontology.org/ontology/ERO',
      'urlprefix' => 'http://purl.bioontology.org/ontology/ERO/{db}:{accession}',
    ]);
    chado_insert_cv(
        'ero',
        'The Eagle-I Research Resource Ontology models research resources such instruments. protocols, reagents, animal models and biospecimens. It has been developed in the context of the eagle-i project (http://eagle-i.net/).'
        );
    
    $term = chado_insert_cvterm([
      'id' => 'ERO:0001716',
      'name' => 'database',
      'cv_name' => 'ero',
      'definition' => 'A database is an organized collection of data, today typically in digital form.',
    ]);
    chado_associate_semweb_term(NULL, 'db_id', $term);
    $term = chado_insert_cvterm([
      'id' => 'ERO:0000387',
      'name' => 'data acquisition',
      'cv_name' => 'ero',
      'definition' => 'A technique that samples real world physical conditions and conversion of the resulting samples into digital numeric values that can be manipulated by a computer.',
    ]);
    chado_associate_semweb_term(NULL, 'acquisition_id', $term);
  }
  
  /**
   * Adds the Information Artifact Ontology database and terms.
   */
  function tripal_chado_populate_vocab_OBCS() {
    chado_insert_db([
      'name' => 'OBCS',
      'description' => 'Ontology of Biological and Clinical Statistics.',
      'url' => 'https://github.com/obcs/obcs',
      'urlprefix' => 'http://purl.obolibrary.org/obo/{db}_{accession}',
    ]);
    chado_insert_cv(
        'OBCS',
        'Ontology of Biological and Clinical Statistics.'
        );
    
    $term = chado_insert_cvterm([
      'id' => 'OBCS:0000117',
      'name' => 'rank order',
      'cv_name' => 'OBCS',
      'definition' => 'A data item that represents an arrangement according to a rank, i.e., the position of a particular case relative to other cases on a defined scale.',
    ]);
    chado_associate_semweb_term(NULL, 'rank', $term);
  }
  
  /**
   * Adds the Information Artifact Ontology database and terms.
   */
  function tripal_chado_populate_vocab_OBI() {
    chado_insert_db([
      'name' => 'OBI',
      'description' => 'The Ontology for Biomedical Investigation.',
      'url' => 'http://obi-ontology.org/page/Main_Page',
      'urlprefix' => 'http://purl.obolibrary.org/obo/{db}_{accession}',
    ]);
    chado_insert_cv(
        'obi',
        'Ontology for Biomedical Investigation. The Ontology for Biomedical Investigations (OBI) is build in a collaborative, international effort and will serve as a resource for annotating biomedical investigations, including the study design, protocols and instrumentation used, the data generated and the types of analysis performed on the data. This ontology arose from the Functional Genomics Investigation Ontology (FuGO) and will contain both terms that are common to all biomedical investigations, including functional genomics investigations and those that are more domain specific.'
        );
    
    $term = chado_insert_cvterm([
      'id' => 'OBI:0100026',
      'name' => 'organism',
      'cv_name' => 'obi',
      'definition' => 'A material entity that is an individual living system, such as animal, plant, bacteria or virus, that is capable of replicating or reproducing, growth and maintenance in the right environment. An organism may be unicellular or made up, like humans, of many billions of cells divided into specialized tissues and organs.',
    ]);
    chado_associate_semweb_term(NULL, 'organism_id', $term);
    chado_associate_semweb_term('biomaterial', 'taxon_id', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'OBI:0000070',
      'name' => 'assay',
      'cv_name' => 'obi',
      'definition' => 'A planned process with the objective to produce information about the material entity that is the evaluant, by physically examining it or its proxies.',
    ]);
  }
  
  /**
   * Adds the Ontology for genetic interval database and terms.
   */
  function tripal_chado_populate_vocab_OGI() {
    chado_insert_db([
      'name' => 'OGI',
      'description' => 'Ontology for genetic interval.',
      'url' => 'http://purl.bioontology.org/ontology/OGI',
      'urlprefix' => 'http://purl.obolibrary.org/obo/{db}_{accession}',
    ]);
    chado_insert_cv(
        'ogi',
        'Ontology for Biomedical Investigation. The Ontology for Biomedical Investigations (OBI) is build in a collaborative, international effort and will serve as a resource for annotating biomedical investigations, including the study design, protocols and instrumentation used, the data generated and the types of analysis performed on the data. This ontology arose from the Functional Genomics Investigation Ontology (FuGO) and will contain both terms that are common to all biomedical investigations, including functional genomics investigations and those that are more domain specific.'
        );
    
    $term = chado_insert_cvterm([
      'id' => 'OGI:0000021',
      'name' => 'location on map',
      'cv_name' => 'ogi',
      'definition' => '',
    ]);
    
  }
  
  /**
   * Adds the Information Artifact Ontology database and terms.
   */
  function tripal_chado_populate_vocab_IAO() {
    
    chado_insert_db([
      'name' => 'IAO',
      'description' => 'The Information Artifact Ontology (IAO).',
      'url' => 'https://github.com/information-artifact-ontology/IAO/',
      'urlprefix' => 'http://purl.obolibrary.org/obo/{db}_{accession}',
    ]);
    chado_insert_cv(
        'IAO',
        'Information Artifact Ontology  is a new ' .
        'ontology of information entities, originally driven by work by the ' .
        'OBI digital entity and realizable information entity branch.'
        );
    
    $term = chado_insert_cvterm([
      'id' => 'IAO:0000115',
      'name' => 'definition',
      'cv_name' => 'IAO',
      'definition' => 'The official OBI definition, explaining the meaning of ' .
      'a class or property. Shall be Aristotelian, formalized and normalized. ' .
      'Can be augmented with colloquial definitions.',
    ]);
    chado_associate_semweb_term(NULL, 'definition', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'IAO:0000129',
      'name' => 'version number',
      'cv_name' => 'IAO',
      'definition' => 'A version number is an ' .
      'information content entity which is a sequence of characters ' .
      'borne by part of each of a class of manufactured products or its ' .
      'packaging and indicates its order within a set of other products ' .
      'having the same name.',
    ]);
    chado_associate_semweb_term('analysis', 'programversion', $term);
    chado_associate_semweb_term('analysis', 'sourceversion', $term);
    chado_associate_semweb_term(NULL, 'version', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'IAO:0000064',
      'name' => 'algorithm',
      'cv_name' => 'IAO',
      'definition' => 'An algorithm is a set of instructions for performing a paticular calculation.',
    ]);
    chado_associate_semweb_term('analysis', 'algorithm', $term);
  }
  
  /**
   * Adds terms to the 'local' database.
   *
   * These are terms where an appropriate match could not be found in any other
   * ontology.
   */
  function tripal_chado_populate_vocab_LOCAL() {
    global $base_path;
    
    chado_insert_db([
      'name' => 'null',
      'description' => 'No online database.',
      'url' => $base_path . 'cv/lookup/null',
      'urlprefix' => $base_path . 'cv/lookup/{db}/{accession}',
    ]);
    chado_insert_db([
      'name' => 'local',
      'description' => 'Terms created for this site.',
      'url' => $base_path . 'cv/lookup/local',
      'urlprefix' => $base_path . 'cv/lookup/{db}/{accession}',
    ]);
    
    
    // ----------------
    // Add the various CV's that fall under the local DB.
    // ----------------
    chado_insert_cv(
        'local',
        'Locally created terms.'
        );
    chado_insert_cv(
        'organism_property',
        'A local vocabulary that contains locally defined properties for organisms'
        );
    chado_insert_cv(
        'analysis_property',
        'A local vocabulary that contains locally defined properties for analyses'
        );
    chado_insert_cv(
        'tripal_phylogeny',
        'Terms used by the Tripal phylotree module for phylogenetic and taxonomic trees.'
        );
    // Add cv for relationship types
    chado_insert_cv(
        'feature_relationship',
        'A local vocabulary that contains types of relationships between features.'
        );
    
    // The feature_property CV may already exists. It comes with Chado, but
    // we need to  add it just in case it doesn't get added before the feature
    // module is installed. But as of Tripal v3.0 the Chado version of this
    // vocabulary is no longer loaded by default.
    chado_insert_cv(
        'feature_property',
        'A local vocabulary that contains properties for genomic features'
        );
    // Add the cv for contact properties. This is a default vocabulary in the event
    // that a user does not want to use the tripal_contact vocabulary
    chado_insert_cv(
        'contact_property',
        'A local vocabulary that contains properties for contacts. This can be used if the tripal_contact vocabulary (which is default for contacts in Tripal) is not desired.'
        );
    
    // add the cv for the contact type. This is a default vocabulary in the event
    // that a user does not want to use the tripal_contact vocabulary
    chado_insert_cv(
        'contact_type',
        'A local vocabulary that contains types of contacts. This can be used if the tripal_contact vocabulary (which is default for contacts in Tripal) is not desired.'
        );
    
    // Add the cv for the tripal_contact vocabulary which is loaded via the OBO
    chado_insert_cv(
        'tripal_contact',
        'A local vocabulary that contains a heirarchical set of terms for describing a contact. It is intended to be used as the default vocabularies in Tripal for contact types and contact properties.'
        );
    
    // add the cv for contact relationships
    chado_insert_cv(
        'contact_relationship',
        'A local vocabulary that contains types of relationships between contacts.'
        );
    chado_insert_cv(
        'featuremap_units',
        'A local vocabulary that contains map unit types for the unittype_id column of the featuremap table.'
        );
    
    chado_insert_cv(
        'featurepos_property',
        'A local vocabulary that contains terms map properties.'
        );
    
    chado_insert_cv(
        'featuremap_property',
        'A local vocabulary that contains positional types for the feature positions'
        );
    chado_insert_cv(
        'library_property',
        'A local vocabulary that contains properties for libraries.'
        );
    chado_insert_cv(
        'library_type',
        'A local vocabulary that contains terms for types of libraries (e.g. BAC, cDNA, FOSMID, etc).'
        );
    // Add the cv for project properties
    chado_insert_cv(
        'project_property',
        'A local vocabulary that contains properties for projects.'
        );
    // Add the cv for project properties
    chado_insert_cv(
        'study_property',
        'A local vocabulary that contains properties for studies.'
        );
    // Add cv for relationship types
    chado_insert_cv(
        'project_relationship',
        'A local vocabulary that contains Types of relationships between projects.'
        );
    // Add the cv for pub properties
    chado_insert_cv(
        'tripal_pub',
        'A local vocabulary that contains a heirarchical set of terms for describing a publication. It is intended to be used as the default vocabularies in Tripal for publication types and contact properties.'
        );
    
    // Add the cv for pub types
    chado_insert_cv(
        'pub_type',
        'A local vocabulary that contains types of publications. This can be used if the tripal_pub vocabulary (which is default for publications in Tripal) is not desired.'
        );
    
    // Add the cv for pub properties
    chado_insert_cv(
        'pub_property',
        'A local vocabulary that contains properties for publications. This can be used if the tripal_pub vocabulary (which is default for publications in Tripal) is not desired.'
        );
    
    // Add cv for relationship types
    chado_insert_cv(
        'pub_relationship',
        'A local vocabulary that contains types of relationships between publications.'
        );
    
    // Add cv for relationship types
    chado_insert_cv(
        'stock_relationship',
        'A local vocabulary that contains types of relationships between stocks.'
        );
    chado_insert_cv(
        'stock_property',
        'A local vocabulary that contains properties for stocks.'
        );
    chado_insert_cv(
        'stock_type',
        'A local vocabulary that contains a list of types for stocks.'
        );
    chado_insert_cv(
        'tripal_analysis',
        'A local vocabulary that contains terms used for analyses.'
        );
    
    //-----------------------------
    // Misc Terms
    //-----------------------------
    $term = chado_insert_cvterm([
      'id' => 'local:property',
      'name' => 'property',
      'cv_name' => 'local',
      'definition' => 'A generic term indicating that represents an attribute, quality or characteristic of something.',
    ]);
    
    //-----------------------------
    // Terms for base table fields
    //-----------------------------
    $term = chado_insert_cvterm([
      'id' => 'local:timelastmodified',
      'name' => 'time_last_modified',
      'cv_name' => 'local',
      'definition' => 'The time at which the record was last modified.',
    ]);
    chado_associate_semweb_term(NULL, 'timelastmodified', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'local:timeaccessioned',
      'name' => 'time_accessioned',
      'cv_name' => 'local',
      'definition' => 'The time at which the record was first added.',
    ]);
    chado_associate_semweb_term(NULL, 'timeaccessioned', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'local:timeexecuted',
      'name' => 'time_executed',
      'cv_name' => 'local',
      'definition' => 'The time when the task was executed.',
    ]);
    chado_associate_semweb_term(NULL, 'timeexecuted', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'local:infraspecific_type',
      'name' => 'infraspecific_type',
      'definition' => 'The connector type (e.g. subspecies, varietas, forma, etc.) for the infraspecific name',
      'cv_name' => 'local',
    ]);
    chado_associate_semweb_term('organism', 'type_id', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'local:abbreviation',
      'name' => 'abbreviation',
      'cv_name' => 'local',
      'definition' => 'A shortened name (or abbreviation) for the item.',
    ]);
    chado_associate_semweb_term('organism', 'abbreviation', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'local:expression',
      'name' => 'expression',
      'definition' => 'Curated expression data',
      'cv_name' => 'local',
    ]);
    
    $term = chado_insert_cvterm([
      'id' => 'local:is_analysis',
      'name' => 'is_analysis',
      'definition' => 'Indicates if this feature was predicted computationally using another feature.',
      'cv_name' => 'local',
    ]);
    chado_associate_semweb_term('feature', 'is_analysis', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'local:is_obsolete',
      'name' => 'is_obsolete',
      'definition' => 'Indicates if this record is obsolete.',
      'cv_name' => 'local',
    ]);
    chado_associate_semweb_term(NULL, 'is_obsolete', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'local:is_current',
      'name' => 'is_current',
      'definition' => 'Indicates if this record is current.',
      'cv_name' => 'local',
    ]);
    chado_associate_semweb_term(NULL, 'is_current', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'local:is_internal',
      'name' => 'is_internal',
      'definition' => 'Indicates if this record is internal and not normally available outside of a local setting.',
      'cv_name' => 'local',
    ]);
    chado_associate_semweb_term(NULL, 'is_internal', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'local:miniref',
      'name' => 'Mini-ref',
      'definition' => 'A small in-house unique identifier for a publication.',
      'cv_name' => 'local',
    ]);
    chado_associate_semweb_term('pub', 'miniref', $term);
    
    
    $term = chado_insert_cvterm([
      'id' => 'local:array_batch_identifier',
      'name' => 'Array Batch Identifier',
      'definition' => 'A unique identifier for an array batch.',
      'cv_name' => 'local',
    ]);
    chado_associate_semweb_term('assay', 'arraybatchidentifier', $term);
    
    //-----------------------------
    // Relationship Terms
    //-----------------------------
    $term = chado_insert_cvterm([
      'id' => 'local:relationship_subject',
      'name' => 'clause subject',
      'definition' => 'The subject of a relationship clause.',
      'cv_name' => 'local',
    ]);
    chado_associate_semweb_term(NULL, 'subject_id', $term);
    chado_associate_semweb_term(NULL, 'subject_reagent_id', $term);
    chado_associate_semweb_term(NULL, 'subject_project_id', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'local:relationship_object',
      'name' => 'clause predicate',
      'definition' => 'The object of a relationship clause.',
      'cv_name' => 'local',
    ]);
    chado_associate_semweb_term(NULL, 'object_id', $term);
    chado_associate_semweb_term(NULL, 'object_reagent_id', $term);
    chado_associate_semweb_term(NULL, 'object_project_id', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'local:relationship_type',
      'name' => 'relationship type',
      'definition' => 'The relationship type.',
      'cv_name' => 'local',
    ]);
    chado_associate_semweb_term('acquisition_relationship', 'type_id', $term);
    chado_associate_semweb_term('biomaterial_relationship', 'type_id', $term);
    chado_associate_semweb_term('cell_line_relationship', 'type_id', $term);
    chado_associate_semweb_term('contact_relationship', 'type_id', $term);
    chado_associate_semweb_term('element_relationship', 'type_id', $term);
    chado_associate_semweb_term('elementresult_relationship', 'type_id', $term);
    chado_associate_semweb_term('feature_relationship', 'type_id', $term);
    chado_associate_semweb_term('nd_reagent_relationship', 'type_id', $term);
    chado_associate_semweb_term('phylonode_relationship', 'type_id', $term);
    chado_associate_semweb_term('project_relationship', 'type_id', $term);
    chado_associate_semweb_term('pub_relationship', 'type_id', $term);
    chado_associate_semweb_term('quantification_relationship', 'type_id', $term);
    chado_associate_semweb_term('stock_relationship', 'type_id', $term);
    chado_associate_semweb_term('cvterm_relationship', 'type_id', $term);
    
    //-----------------------------
    // NCBI Organism Property Terms
    //-----------------------------
    // TODO: these probably have real terms we can use.
    
    $term = chado_insert_cvterm([
      'id' => 'local:rank',
      'name' => 'rank',
      'definition' => 'A taxonmic rank',
      'cv_name' => 'local',
    ]);
    
    $terms = [
      'lineage',
      'genetic_code',
      'genetic_code_name',
      'mitochondrial_genetic_code',
      'mitochondrial_genetic_code_name',
      'division',
      'genbank_common_name',
      'synonym',
      'other_name',
      'equivalent_name',
      'anamorph',
    ];
    $options = ['update_existing' => TRUE];
    foreach ($terms as $term) {
      $value = [
        'name' => $term,
        'definition' => '',
        'cv_name' => 'organism_property',
        'db_name' => 'local',
      ];
      chado_insert_cvterm($value, $options);
    }
    
    //---------------------
    // Phylogeny Tree Terms
    //---------------------
    
    // Add the terms used to identify nodes in the tree.
    chado_insert_cvterm([
      'name' => 'phylo_leaf',
      'definition' => 'A leaf node in a phylogenetic tree.',
      'cv_name' => 'tripal_phylogeny',
      'is_relationship' => 0,
      'db_name' => 'local',
    ]);
    // Add the terms used to identify nodes in the tree.
    chado_insert_cvterm([
      'name' => 'phylo_root',
      'definition' => 'The root node of a phylogenetic tree.',
      'cv_name' => 'tripal_phylogeny',
      'is_relationship' => 0,
      'db_name' => 'local',
    ]);
    
    // Add the terms used to identify nodes in the tree.
    chado_insert_cvterm([
      'name' => 'phylo_interior',
      'definition' => 'An interior node in a phylogenetic tree.',
      'cv_name' => 'tripal_phylogeny',
      'is_relationship' => 0,
      'db_name' => 'local',
    ]);
    
    // Add the terms used to identify nodes in the tree.
    // DEPRECATED: use EDAM's data 'Species tree' term instead.
    chado_insert_cvterm([
      'name' => 'taxonomy',
      'definition' => 'A term used to indicate if a phylotree is a taxonomic tree',
      'cv_name' => 'tripal_phylogeny',
      'is_relationship' => 0,
      'db_name' => 'local',
    ]);
    
    //--------------
    // Project Terms
    //--------------
    // Insert cvterm 'Project Description' into cvterm table of chado
    // database. This CV term is used to keep track of the project
    // description in the projectprop table.
    chado_insert_cvterm([
      'name' => 'Project Description',
      'definition' => 'Description of a project',
      'cv_name' => 'project_property',
      'db_name' => 'local',
    ]);
    
    chado_insert_cvterm([
      'name' => 'Project Type',
      'definition' => 'A type of project',
      'cv_name' => 'project_property',
      'db_name' => 'local',
    ]);
    
    //--------------
    // Natural Diversity Terms
    //--------------
    // add cvterms for the nd_experiment_types
    chado_insert_cvterm([
      'name' => 'Genotyping',
      'definition' => 'An experiment where genotypes of individuals are identified.',
      'cv_name' => 'nd_experiment_types',
      'db_name' => 'local',
    ]);
    
    chado_insert_cvterm([
      'name' => 'Phenotyping',
      'definition' => 'An experiment where phenotypes of individuals are identified.',
      'cv_name' => 'nd_experiment_types',
      'db_name' => 'local',
    ]);
    
    chado_insert_cvterm([
      'name' => 'Location',
      'definition' => 'The name of the location.',
      'cv_name' => 'nd_geolocation_property',
      'db_name' => 'local',
    ]);
    
    
    //--------------
    // Library Terms
    //--------------
    $term = chado_insert_cvterm([
      'id' => 'local:library',
      'name' => 'Library',
      'definition' => 'A group of physical entities organized into a collection',
      'cv_name' => 'local',
      'db_name' => 'local',
    ]);
    chado_associate_semweb_term(NULL, 'library_id', $term);
    
    // Insert cvterm 'library_description' into cvterm table of chado
    // database. This CV term is used to keep track of the library
    // description in the libraryprop table.
    $term = chado_insert_cvterm([
      'id' => 'local:library_description',
      'name' => 'Library Description',
      'definition' => 'Description of a library',
      'cv_name' => 'library_property',
      'db_name' => 'local',
    ]);
    
    // add cvterms for the map unit types
    $term = chado_insert_cvterm([
      'id' => 'local:cdna_library',
      'name' => 'cdna_library',
      'definition' => 'cDNA library',
      'cv_name' => 'library_type',
      'db_name' => 'local',
    ]);
    $term = chado_insert_cvterm([
      'id' => 'local:bac_library',
      'name' => 'bac_library',
      'definition' => 'Bacterial Artifical Chromsome (BAC) library',
      'cv_name' => 'library_type',
      'db_name' => 'local',
    ]);
    $term = chado_insert_cvterm([
      'id' => 'local:fosmid_library',
      'name' => 'fosmid_library',
      'definition' => 'Fosmid library',
      'cv_name' => 'library_type',
      'db_name' => 'local',
    ]);
    $term = chado_insert_cvterm([
      'id' => 'local:cosmid_library',
      'name' => 'cosmid_library',
      'definition' => 'Cosmid library',
      'cv_name' => 'library_type',
      'db_name' => 'local',
    ]);
    $term = chado_insert_cvterm([
      'id' => 'local:yac_library',
      'name' => 'yac_library',
      'definition' => 'Yeast Artificial Chromosome (YAC) library',
      'cv_name' => 'library_type',
      'db_name' => 'local',
    ]);
    $term = chado_insert_cvterm([
      'id' => 'local:genomic_library',
      'name' => 'genomic_library',
      'definition' => 'Genomic Library',
      'cv_name' => 'library_type',
      'db_name' => 'local',
    ]);
    
    //--------
    // Feature
    //--------
    $term = chado_insert_cvterm([
      'name' => 'fasta_definition',
      'definition' => 'The definition line for a FASTA formatted sequence',
      'cv_name' => 'local',
      'is_relationship' => 0,
      'db_name' => 'local',
    ]);
    
    //--------------
    // Feature Map
    //--------------
    // add cvterms for the map unit types
    $term = chado_insert_cvterm([
      'name' => 'cM',
      'definition' => 'Centimorgan units',
      'cv_name' => 'featuremap_units',
      'is_relationship' => 0,
      'db_name' => 'local',
    ]);
    $term = chado_insert_cvterm([
      'name' => 'bp',
      'definition' => 'Base pairs units',
      'cv_name' => 'featuremap_units',
      'is_relationship' => 0,
      'db_name' => 'local',
    ]);
    $term = chado_insert_cvterm([
      'name' => 'bin_unit',
      'definition' => 'The bin unit',
      'cv_name' => 'featuremap_units',
      'is_relationship' => 0,
      'db_name' => 'local',
    ]);
    $term = chado_insert_cvterm([
      'name' => 'marker_order',
      'definition' => 'Units simply to define marker order.',
      'cv_name' => 'featuremap_units',
      'is_relationship' => 0,
      'db_name' => 'local',
    ]);
    $term = chado_insert_cvterm([
      'name' => 'undefined',
      'definition' => 'A catch-all for an undefined unit type',
      'cv_name' => 'featuremap_units',
      'is_relationship' => 0,
      'db_name' => 'local',
    ]);
    // featurepos properties
    $term = chado_insert_cvterm([
      'name' => 'start',
      'definition' => 'The start coordinate for a map feature.',
      'cv_name' => 'featurepos_property',
      'is_relationship' => 0,
      'db_name' => 'local',
    ]);
    $term = chado_insert_cvterm([
      'name' => 'stop',
      'definition' => 'The end coordinate for a map feature',
      'cv_name' => 'featurepos_property',
      'is_relationship' => 0,
      'db_name' => 'local',
    ]);
    // add cvterms for map properties
    $term = chado_insert_cvterm([
      'name' => 'Map Dbxref',
      'definition' => 'A unique identifer for the map in a remote database.  The '
      . 'format is a database abbreviation and a unique accession separated '
      . 'by a colon.  (e.g. Gramene:tsh1996a)',
      'cv_name' => 'featuremap_property',
      'is_relationship' => 0,
      'db_name' => 'local',
    ]);
    $term = chado_insert_cvterm([
      'name' => 'Map Type',
      'definition' => 'The type of Map (e.g. QTL, Physical, etc.)',
      'cv_name' => 'featuremap_property',
      'is_relationship' => 0,
      'db_name' => 'local',
    ]);
    $term = chado_insert_cvterm([
      'name' => 'Genome Group',
      'definition' => '',
      'cv_name' => 'featuremap_property',
      'is_relationship' => 0,
      'db_name' => 'local',
    ]);
    $term = chado_insert_cvterm([
      'name' => 'URL',
      'definition' => 'A univeral resource locator (URL) reference where the '
      . 'publication can be found.  For maps found online, this would be '
      . 'the web address for the map.',
      'cv_name' => 'featuremap_property',
      'is_relationship' => 0,
      'db_name' => 'local',
    ]);
    $term = chado_insert_cvterm([
      'name' => 'Population Type',
      'definition' => 'A brief description of the population type used to generate '
      . 'the map (e.g. RIL, F2, BC1, etc).',
      'cv_name' => 'featuremap_property',
      'is_relationship' => 0,
      'db_name' => 'local',
    ]);
    $term = chado_insert_cvterm([
      'name' => 'Population Size',
      'definition' => 'The size of the population used to construct the map.',
      'cv_name' => 'featuremap_property',
      'is_relationship' => 0,
      'db_name' => 'local',
    ]);
    $term = chado_insert_cvterm([
      'name' => 'Methods',
      'definition' => 'A brief description of the methods used to construct the map.',
      'cv_name' => 'featuremap_property',
      'is_relationship' => 0,
      'db_name' => 'local',
    ]);
    $term = chado_insert_cvterm([
      'name' => 'Software',
      'definition' => 'The software used to construct the map.',
      'cv_name' => 'featuremap_property',
      'is_relationship' => 0,
      'db_name' => 'local',
    ]);
    
    $term = chado_insert_cvterm([
      'name' => 'Reference Feature',
      'definition' => 'A genomic or genetic feature on which other features are mapped.',
      'cv_name' => 'local',
      'is_relationship' => 0,
      'db_name' => 'local',
    ]);
    chado_associate_semweb_term('featurepos', 'map_feature_id', $term);
    
    
    //--------------
    // Featureloc Terms
    //--------------
    $term = chado_insert_cvterm([
      'id' => 'local:fmin',
      'name' => 'minimal boundary',
      'definition' => 'The leftmost, minimal boundary in the linear range ' .
      'represented by the feature location. Sometimes this is called ' .
      'start although this is confusing because it does not necessarily ' .
      'represent the 5-prime coordinate.',
      'cv_name' => 'local',
    ]);
    chado_associate_semweb_term('featureloc', 'fmin', $term);
    $term = chado_insert_cvterm([
      'id' => 'local:fmax',
      'name' => 'maximal boundary',
      'definition' => 'The rightmost, maximal boundary in the linear range ' .
      'represented by the featureloc. Sometimes this is called end although ' .
      'this is confusing because it does not necessarily represent the ' .
      '3-prime coordinate',
      'cv_name' => 'local',
    ]);
    chado_associate_semweb_term('featureloc', 'fmax', $term);
    
    
    //--------------
    // Analysis Terms
    //--------------
    // add analysis_date.  This is no longer used (as far as we can tell) but we don't
    // get rid of it in case it is used, so just keep it in the Tripal CV
    $term = chado_insert_cvterm([
      'name' => 'analysis_date',
      'definition' => 'The date that an analysis was performed.',
      'cv_name' => 'tripal_analysis',
      'is_relationship' => 0,
      'db_name' => 'local',
    ], ['update_existing' => TRUE]);
    
    // add analysis_short_name.  This is no longer used (as far as we can tell) but we don't
    // get rid of it in case it is used, so just keep it in the Tripal CV
    $term = chado_insert_cvterm([
      'name' => 'analysis_short_name',
      'definition' => 'A computer legible (no spaces or special characters) '
      . 'abbreviation for the analysis.',
      'cv_name' => 'tripal_analysis',
      'is_relationship' => 0,
      'db_name' => 'local',
    ], ['update_existing' => TRUE]);
    
    
    // the 'analysis_property' vocabulary is for user definable properties wo we
    // will add an 'Analysis Type' to this vocubulary
    $term = chado_insert_cvterm([
      'id' => 'local:Analysis Type',
      'name' => 'Analysis Type',
      'definition' => 'The type of analysis that was performed.',
      'cv_name' => 'analysis_property',
      'is_relationship' => 0,
      'db_name' => 'local',
    ], ['update_existing' => TRUE]);
    
    // Add a term to be used for an inherent 'type_id' for the organism table.
    $term = chado_insert_cvterm([
      'id' => 'local:analysis',
      'name' => 'analysis',
      'definition' => 'A process as a method of studying the nature of something ' .
      'or of determining its essential features and their relations. ' .
      '(Random House Kernerman Webster\'s College Dictionary, © 2010 K ' .
      'Dictionaries Ltd).',
      'cv_name' => 'local',
    ]);
    
    $term = chado_insert_cvterm([
      'id' => 'local:source_data',
      'name' => 'source_data',
      'definition' => 'The location where data that is being used come from.',
      'cv_name' => 'local',
    ]);
    
    //--------------
    // Terms for Content Types
    //--------------
    $term = chado_insert_cvterm([
      'id' => 'local:contact',
      'name' => 'contact',
      'definition' => 'An entity (e.g. individual or organization) through ' .
      'whom a person can gain access to information, favors, ' .
      'influential people, and the like.',
      'cv_name' => 'local',
    ]);
    chado_associate_semweb_term('biomaterial', 'biosourceprovider_id', $term);
    chado_associate_semweb_term(NULL, 'contact_id', $term);
    
    
    $term = chado_insert_cvterm([
      'id' => 'local:relationship',
      'name' => 'relationship',
      'definition' => 'The way in which two things are connected.',
      'cv_name' => 'local',
    ]);
    
    $term = chado_insert_cvterm([
      'id' => 'local:biomaterial',
      'name' => 'biomaterial',
      'definition' => 'A biomaterial represents the MAGE concept of BioSource, BioSample, ' .
      'and LabeledExtract. It is essentially some biological material (tissue, cells, serum) that ' .
      'may have been processed. Processed biomaterials should be traceable back to raw ' .
      'biomaterials via the biomaterialrelationship table.',
      'cv_name' => 'local',
    ]);
    
    //
    // Terms for arraydesign table
    //
    $term = chado_insert_cvterm([
      'id' => 'local:array_dimensions',
      'name' => 'array_dimensions',
      'definition' => 'The dimensions of an array.',
      'cv_name' => 'local',
    ]);
    chado_associate_semweb_term('arraydesign', 'array_dimensions', $term);
    $term = chado_insert_cvterm([
      'id' => 'local:element_dimensions',
      'name' => 'element_dimensions',
      'definition' => 'The dimensions of an element.',
      'cv_name' => 'local',
    ]);
    chado_associate_semweb_term('arraydesign', 'element_dimensions', $term);
    $term = chado_insert_cvterm([
      'id' => 'local:num_of_elements',
      'name' => 'num_of_elements',
      'definition' => 'The number of elements.',
      'cv_name' => 'local',
    ]);
    chado_associate_semweb_term('arraydesign', 'num_of_elements', $term);
    $term = chado_insert_cvterm([
      'id' => 'local:num_array_columns',
      'name' => 'num_array_columns',
      'definition' => 'The number of columns in an array.',
      'cv_name' => 'local',
    ]);
    chado_associate_semweb_term('arraydesign', 'num_array_columns', $term);
    $term = chado_insert_cvterm([
      'id' => 'local:num_array_rows',
      'name' => 'num_array_rows',
      'definition' => 'The number of rows in an array.',
      'cv_name' => 'local',
    ]);
    chado_associate_semweb_term('arraydesign', 'num_array_rows', $term);
    $term = chado_insert_cvterm([
      'id' => 'local:num_grid_columns',
      'name' => 'num_grid_columns',
      'definition' => 'The number of columns in a grid.',
      'cv_name' => 'local',
    ]);
    chado_associate_semweb_term('arraydesign', 'num_grid_columns', $term);
    $term = chado_insert_cvterm([
      'id' => 'local:num_grid_rows',
      'name' => 'num_grid_rows',
      'definition' => 'The number of rows in a grid.',
      'cv_name' => 'local',
    ]);
    chado_associate_semweb_term('arraydesign', 'num_grid_rows', $term);
    $term = chado_insert_cvterm([
      'id' => 'local:num_sub_columns',
      'name' => 'num_sub_columns',
      'definition' => 'The number of sub columns.',
      'cv_name' => 'local',
    ]);
    chado_associate_semweb_term('arraydesign', 'num_sub_columns', $term);
    $term = chado_insert_cvterm([
      'id' => 'local:num_sub_rows',
      'name' => 'num_sub_rows',
      'definition' => 'The number of sub rows.',
      'cv_name' => 'local',
    ]);
    chado_associate_semweb_term('arraydesign', 'num_sub_rows', $term);
    
    
    //
    // Terms for Study
    //
    chado_insert_cvterm([
      'name' => 'Study Type',
      'definition' => 'A type of study',
      'cv_name' => 'study_property',
      'db_name' => 'local',
    ]);
    
    $term = chado_insert_cvterm([
      'id' => 'local:Genome Project',
      'name' => 'Genome Project',
      'definition' => 'A project for whole genome analysis that can include assembly and annotation.',
      'cv_name' => 'local',
    ]);
    
  }
  
  /**
   * Adds the Systems Biology Ontology database and terms.
   */
  function tripal_chado_populate_vocab_SBO() {
    chado_insert_db([
      'name' => 'SBO',
      'description' => 'Systems Biology.',
      'url' => 'http://www.ebi.ac.uk/sbo/main/',
      'urlprefix' => 'http://purl.obolibrary.org/obo/{db}_{accession}',
    ]);
    chado_insert_cv(
        'sbo',
        'Systems Biology.  Terms commonly used in Systems Biology, and in particular in computational modeling.'
        );
    
    $dbxref = chado_insert_cvterm([
      'id' => 'SBO:0000358',
      'name' => 'phenotype',
      'cv_name' => 'sbo',
      'definition' => 'A biochemical network can generate phenotypes or affects biological processes. Such processes can take place at different levels and are independent of the biochemical network itself.',
    ]);
    
    $dbxref = chado_insert_cvterm([
      'id' => 'SBO:0000554',
      'name' => 'database cross reference',
      'cv_name' => 'sbo',
      'definition' => 'An annotation which directs one to information contained within a database.',
    ]);
    
    $relationship = chado_insert_cvterm([
      'id' => 'SBO:0000374',
      'name' => 'relationship',
      'cv_name' => 'sbo',
      'definition' => 'Connectedness between entities and/or interactions representing their relatedness or influence.',
    ]);
  }
  
  /**
   * Adds the "Bioinformatics operations, data types, formats, identifiers and
   * topics" database and terms.
   */
  function tripal_chado_populate_vocab_SWO() {
    chado_insert_db([
      'name' => 'SWO',
      'description' => 'Bioinformatics operations, data types, formats, identifiers and topics',
      'url' => 'http://purl.obolibrary.org/obo/swo',
      'urlprefix' => 'http://www.ebi.ac.uk/swo/{db}_{accession}',
    ]);
    chado_insert_cv('swo', 'Bioinformatics operations, data types, formats, identifiers and topics.');
    
    $term = chado_insert_cvterm([
      'id' => 'SWO:0000001',
      'name' => 'software',
      'cv_name' => 'swo',
      'definition' => 'Computer software, or generally just software, is any ' .
      'set of machine-readable instructions (most often in the form of a ' .
      'computer program) that conform to a given syntax (sometimes ' .
      'referred to as a language) that is interpretable by a given ' .
      'processor and that directs a computer\'s processor to perform ' .
      'specific operations.',
    ]);
    chado_associate_semweb_term('analysis', 'program', $term);
    chado_associate_semweb_term('protocol', 'softwaredescription', $term);
  }
  
  /**
   * Adds the contact table mapping.
   */
  function tripal_chado_populate_vocab_TCONTACT() {
    chado_insert_db([
      'name' => 'TCONTACT',
      'description' => 'Tripal Contact Ontology. A temporary ontology until a more formal appropriate ontology an be identified.',
      'url' => 'cv/lookup/TCONTACT',
      'urlprefix' => 'cv/lookup/TCONTACT/{accession}',
    ]);
    chado_insert_cv('tripal_contact', 'Tripal Contact Ontology. A temporary ontology until a more formal appropriate ontology an be identified.');
  }
  
  /**
   * Adds the pub table mappings.
   */
  function tripal_chado_populate_vocab_TPUB() {
    
    chado_insert_db([
      'name' => 'TPUB',
      'description' => 'Tripal Publication Ontology. A temporary ontology until a more formal appropriate ontology an be identified.',
      'url' => 'cv/lookup/TPUB',
      'urlprefix' => 'cv/lookup/TPUB/{accession}',
    ]);
    chado_insert_cv('tripal_pub', 'Tripal Publication Ontology. A temporary ontology until a more formal appropriate ontology an be identified.');
    
    // make sure we have our supported databases
    chado_insert_db(
        [
          'name' => 'PMID',
          'description' => 'PubMed',
          'url' => 'http://www.ncbi.nlm.nih.gov/pubmed',
          'urlprefix' => 'http://www.ncbi.nlm.nih.gov/pubmed/{accession}',
        ],
        ['update_existing' => TRUE]
        );
    chado_insert_db(
        [
          'name' => 'AGL',
          'description' => 'USDA National Agricultural Library',
          'url' => 'http://agricola.nal.usda.gov/',
        ],
        ['update_existing' => TRUE]
        );
    $term = chado_get_cvterm(['id' => 'TPUB:0000039']);
    chado_associate_semweb_term('pub', 'title', $term);
    
    $term = chado_get_cvterm(['id' => 'TPUB:0000243']);
    chado_associate_semweb_term('pub', 'volumetitle', $term);
    
    $term = chado_get_cvterm(['id' => 'TPUB:0000042']);
    chado_associate_semweb_term('pub', 'volume', $term);
    
    $term = chado_get_cvterm(['id' => 'TPUB:0000256']);
    chado_associate_semweb_term('pub', 'series_name', $term);
    
    $term = chado_get_cvterm(['id' => 'TPUB:0000043']);
    chado_associate_semweb_term('pub', 'issue', $term);
    
    $term = chado_get_cvterm(['id' => 'TPUB:0000059']);
    chado_associate_semweb_term('pub', 'pyear', $term);
    
    $term = chado_get_cvterm(['id' => 'TPUB:0000044']);
    chado_associate_semweb_term('pub', 'pages', $term);
    
    $term = chado_get_cvterm(['id' => 'TPUB:0000244']);
    chado_associate_semweb_term('pub', 'publisher', $term);
    
    $term = chado_get_cvterm(['id' => 'TPUB:0000245']);
    chado_associate_semweb_term('pub', 'pubplace', $term);
  }
  
  /**
   * Adds the Uni Ontology database, terms and mappings.
   */
  function tripal_chado_populate_vocab_UO() {
    chado_insert_db([
      'name' => 'UO',
      'description' => 'Units of Measurement Ontology',
      'url' => 'http://purl.obolibrary.org/obo/uo',
      'urlprefix' => 'http://purl.obolibrary.org/obo/TAXRANK_',
    ]);
    chado_insert_cv('uo', 'Units of Measurement Ontology');
    
    $term = chado_insert_cvterm([
      'id' => 'UO:0000000',
      'name' => 'unit',
      'cv_name' => 'uo',
      'description' => 'A unit of measurement is a standardized quantity of a physical quality.',
    ]);
    chado_associate_semweb_term('featuremap', 'unittype_id', $term);
  }
  
  /**
   * Adds the Taxonomic Rank Ontology database and terms.
   */
  function tripal_chado_populate_vocab_TAXRANK() {
    
    chado_insert_db([
      'name' => 'TAXRANK',
      'description' => 'A vocabulary of taxonomic ranks (species, family, phylum, etc)',
      'url' => 'http://www.obofoundry.org/ontology/taxrank.html',
      'urlprefix' => 'http://purl.obolibrary.org/obo/{db}_{accession}',
    ]);
    chado_insert_cv('taxonomic_rank', 'A vocabulary of taxonomic ranks (species, family, phylum, etc)');
    
    $term = chado_get_cvterm(['id' => 'TAXRANK:0000005']);
    chado_associate_semweb_term('organism', 'genus', $term);
    
    $term = chado_get_cvterm(['id' => 'TAXRANK:0000006']);
    chado_associate_semweb_term('organism', 'species', $term);
    
    $term = chado_get_cvterm(['id' => 'TAXRANK:0000045']);
    chado_associate_semweb_term('organism', 'infraspecific_name', $term);
  }
  
  /**
   * Adds the NCIT vocabulary database and terms.
   */
  function tripal_chado_populate_vocab_NCIT() {
    chado_insert_db([
      'name' => 'NCIT',
      'description' => 'NCI Thesaurus OBO Edition.',
      'url' => 'http://purl.obolibrary.org/obo/ncit.owl',
      'urlprefix' => ' http://purl.obolibrary.org/obo/{db}_{accession}',
    ]);
    chado_insert_cv(
        'ncit',
        'The NCIt OBO Edition project aims to increase integration of the NCIt with OBO Library ontologies. NCIt is a reference terminology that includes broad coverage of the cancer domain, including cancer related diseases, findings and abnormalities. NCIt OBO Edition releases should be considered experimental.'
        );
    
    $term = chado_insert_cvterm([
      'id' => 'NCIT:C25164',
      'name' => 'Date',
      'cv_name' => 'ncit',
      'definition' => 'The particular day, month and year an event has happened or will happen.',
    ]);
    chado_associate_semweb_term('assay', 'assaydate', $term);
    chado_associate_semweb_term('acquisition', 'acquisitiondate', $term);
    chado_associate_semweb_term('quantification', 'quantificationdate', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'NCIT:C48036',
      'name' => 'Operator',
      'cv_name' => 'ncit',
      'definition' => 'A person that operates some apparatus or machine',
    ]);
    chado_associate_semweb_term(NULL, 'operator_id', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'NCIT:C45378',
      'name' => 'Technology Platform',
      'cv_name' => 'ncit',
      'definition' => 'The specific version (manufacturer, model, etc.) of a technology that is used to carry out a laboratory or computational experiment.',
    ]);
    chado_associate_semweb_term('arraydesign', 'platformtype_id', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'NCIT:C25712',
      'name' => 'Value',
      'cv_name' => 'ncit',
      'definition' => 'A numerical quantity measured or assigned or computed.',
    ]);
    chado_associate_semweb_term(NULL, 'value', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'NCIT:C44170',
      'name' => 'Channel',
      'cv_name' => 'ncit',
      'definition' => 'An independent acquisition scheme, i.e., a route or conduit through which flows data consisting of one particular measurement using one particular parameter.',
    ]);
    chado_associate_semweb_term(NULL, 'channel_id', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'NCIT:C48697',
      'name' => 'Controlled Vocabulary',
      'cv_name' => 'ncit',
      'definition' => 'A set of terms that are selected and defined based on the requirements set out by the user group, usually a set of vocabulary is chosen to promote consistency across data collection projects. [ NCI ]',
    ]);
    chado_associate_semweb_term(NULL, 'cv_id', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'NCIT:C45559',
      'name' => 'Term',
      'cv_name' => 'ncit',
      'definition' => 'A word or expression used for some particular thing. [ NCI ]',
    ]);
    chado_associate_semweb_term(NULL, 'cvterm_id', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'NCIT:C80488',
      'name' => 'Expression',
      'cv_name' => 'ncit',
      'definition' => 'A combination of symbols that represents a value. [ NCI ]',
    ]);
    chado_associate_semweb_term(NULL, 'expression_id', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'NCIT:C16977',
      'name' => 'Phenotype',
      'cv_name' => 'ncit',
      'definition' => 'The assemblage of traits or outward appearance of an individual. It is the product of interactions between genes and between genes and the environment. [ NCI ]',
    ]);
    chado_associate_semweb_term(NULL, 'phenotype_id', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'NCIT:C16631',
      'name' => 'Genotype',
      'cv_name' => 'ncit',
      'definition' => 'The genetic constitution of an organism or cell, as distinct from its expressed features or phenotype. [ NCI ]',
    ]);
    chado_associate_semweb_term(NULL, 'genotype_id', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'NCIT:C25341',
      'name' => 'Location',
      'cv_name' => 'ncit',
      'definition' => 'A position, site, or point in space where something can be found. [ NCI ]',
    ]);
    chado_associate_semweb_term(NULL, 'nd_geolocation_id', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'NCIT:C802',
      'name' => 'Reagent',
      'cv_name' => 'ncit',
      'definition' => 'Any natural or synthetic substance used in a chemical or biological reaction in order to produce, identify, or measure another substance. [ NCI ]',
    ]);
    chado_associate_semweb_term(NULL, 'nd_reagent_id', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'NCIT:C16551',
      'name' => 'Environment',
      'cv_name' => 'ncit',
      'definition' => 'The totality of surrounding conditions. [ NCI ]',
    ]);
    chado_associate_semweb_term(NULL, 'environment_id', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'NCIT:C42765',
      'name' => 'Tree Node',
      'cv_name' => 'ncit',
      'definition' => 'A term that refers to any individual item or entity in a hierarchy or pedigree. [ NCI ]',
    ]);
    chado_associate_semweb_term(NULL, 'phylonode_id', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'NCIT:C15320',
      'name' => 'Study Design',
      'cv_name' => 'ncit',
      'definition' => 'A plan detailing how a study will be performed in order to represent the phenomenon under examination, to answer the research questions that have been asked, and defining the methods of data analysis. Study design is driven by research hypothesis being posed, study subject/population/sample available, logistics/resources: technology, support, networking, collaborative support, etc. [ NCI ]',
    ]);
    chado_associate_semweb_term(NULL, 'studydesign_id', $term);
    
    // The Company term is missing for the Tripal Contact ontology, but is
    // useful for the arraydesign.manufacturer which is an FK to Contact.
    // It seems better to use a term from a curated ontology than to add to
    // Tripal Contact.
    $term = chado_insert_cvterm([
      'id' => 'NCIT:C54131',
      'name' => 'Company',
      'cv_name' => 'ncit',
      'definition' => 'Any formal business entity for profit, which may be a corporation, a partnership, association or individual proprietorship.',
    ]);
    
    $term = chado_insert_cvterm([
      'id' => 'NCIT:C47885',
      'name' => 'Project',
      'cv_name' => 'ncit',
      'definition' => 'Any specifically defined piece of work that is undertaken or attempted to meet a single requirement.',
    ]);
    chado_associate_semweb_term(NULL, 'project_id', $term);
    
    $term = chado_insert_cvterm([
      'id' => 'NCIT:C16223',
      'name' => 'DNA Library',
      'cv_name' => 'ncit',
      'definition' => 'A collection of DNA molecules that have been cloned in vectors.',
    ]);
    
    $term = chado_insert_cvterm([
      'id' => 'NCIT:C85496',
      'name' => 'Trait',
      'cv_name' => 'ncit',
      'definition' => 'Any genetically determined characteristic.',
    ]);
    
    $term = chado_insert_cvterm([
      'id' => 'NCIT:C25693',
      'name' => 'Subgroup',
      'cv_name' => 'ncit',
      'definition' => 'A subdivision of a larger group with members often exhibiting similar characteristics. [ NCI ]',
    ]);
    
  }
}
