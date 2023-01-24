<?php

namespace Drupal\Tests\tripal_chado\Functional;

use Drupal\tripal_chado\TripalStorage\ChadoIntStoragePropertyType;
use Drupal\tripal_chado\TripalStorage\ChadoVarCharStoragePropertyType;
use Drupal\tripal_chado\TripalStorage\ChadoTextStoragePropertyType;
use Drupal\tripal\TripalStorage\StoragePropertyValue;
use Drupal\tripal\TripalVocabTerms\TripalTerm;
use Drupal\Tests\tripal_chado\Functional\MockClass\FieldConfigMock;

// FROM OLD CODE:
use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use Drupal\Core\Database\Database;
use Drupal\tripal_chado\api\ChadoSchema;
use GFF3Importer;

/**
 * Tests for the GFF3Importer class
 *
 * @group Tripal
 * @group Tripal Chado
 * @group Tripal Chado ChadoStorage
 */
class GFF3ImporterTest extends ChadoTestBrowserBase
{

  /**
   * Confirm basic GFF importer functionality.
   *
   * @group gff
   */
  public function testGFFImporterSimpleTest()
  {
    $public = \Drupal::database();

    // Installs up the chado with the test chado data
    $chado = $this->getTestSchema(ChadoTestBrowserBase::PREPARE_TEST_CHADO);

    // Keep track of the schema name in case we need it
    $schema_name = $chado->getSchemaName();


    // Test to ensure cvterms are found in the cvterms table
    $cvterms_count_query = $chado->query("SELECT count(*) as c1 FROM {1:cvterm}");
    $cvterms_count_object = $cvterms_count_query->fetchObject();
    $this->assertNotEquals($cvterms_count_object->c1, 0);

    // Insert organism
    $organism_id = $chado->insert('1:organism')
      ->fields([
        'genus' => 'Citrus',
        'species' => 'sinensis',
        'common_name' => 'Sweet Orange',
      ])
      ->execute();

    // Insert Analysis
    $analysis_id = $chado->insert('1:analysis')
      ->fields([
        'name' => 'Test Analysis',
        'description' => 'Test Analysis',
        'program' => 'PROGRAM',
        'programversion' => '1.0',
      ])
      ->execute();


    // Verify that gene is now in the cvterm table (which gets imported from SO obo)
    $result_gene_cvterm = $chado->query("SELECT * FROM {1:cvterm} WHERE name = 'gene' LIMIT 1;");
    $cvterm_object = null;
    $cvterm_object = $result_gene_cvterm->fetchObject();
    $this->assertNotEquals($cvterm_object, null);


    // Import landmarks from fixture
    // $chado->executeSqlFile(__DIR__ . '/../../../fixtures/gff3_loader/landmarks.sql');

    // Manually insert landmarks into features table
    $chado->query("INSERT INTO {1:feature} (dbxref_id, organism_id, name, uniquename, residues, seqlen, md5checksum, type_id, is_analysis, is_obsolete, timeaccessioned, timelastmodified) VALUES (NULL, 1, 'scaffold1', 'scaffold1', 'CAACAAGAAGTAAGCATAGGTTAATTATCATCCACGCATATTAATCAAGAATCGATGCTCGATTAATGTTTTTGAATTGACAAACAAAAGTTTTGTAAAAAGGACTTGTTGGTGGTGGTGGGGTGGTGGTGATGGTGTGGTGGGTAGGTCGCTGGTCGTCGCCGGCGTGGTGGAAGTCTCGCTGGCCGGTGTCTCGGCGGTCTGGTGGCGGCTGGTGGCGGTAGTTGTGAGTTTTTTCTTTCTTTTTTTGTTTTTTTTTTTTACTTTTTACTTTTTTTTCGTCTTGAACAAATTAAAAATAGAGTTTGTTTGTATTTGGTTATTATTTATTGATAAGGGTATATTCGTCCTGTTTGGTCTTGATGTAATAAAATTAAATTAATTTACGGGCTTCAACTAATAAACTCCTTCATGTTGGTTTGAACTAATAAAAAAAGGGGAAATTTGCTAGACACCCCTAATTTTGGACTTATATGGGTAGAAGTCCTAGTTGCTAGATGAATATAGGCCTAGGTCCATCCACATAAAAAAATAATATAAATTAAATAATAAAAATAATATATAGACATAAGTACCCTTATTGAATAAACATATTTTAGGGGATTCAGTTATATACGTAAAGTTGGGAAATCAAATCCCACTAATCACGATTGAAGGCAGAGTATCGTGTAAGACGTTTGGAAAACATATCTTAGTCGATTCCAGTGGAATATGAGATCA', 720, '83578d8afdaec399c682aa6c0ddd29c9', 474, false, false, '2022-11-28 21:44:51.006276', '2022-11-28 21:44:51.006276');");
    $chado->query("INSERT INTO {1:feature} (dbxref_id, organism_id, name, uniquename, residues, seqlen, md5checksum, type_id, is_analysis, is_obsolete, timeaccessioned, timelastmodified) VALUES (NULL, 1, 'Contig10036', 'Contig10036', '', 0, 'd41d8cd98f00b204e9800998ecf8427e', 474, false, false, '2022-11-26 05:39:55.810798', '2022-11-26 05:39:55.810798')");
    $chado->query("INSERT INTO {1:feature} (dbxref_id, organism_id, name, uniquename, residues, seqlen, md5checksum, type_id, is_analysis, is_obsolete, timeaccessioned, timelastmodified) VALUES (NULL, 1, 'Contig1', 'Contig1', '', 0, 'd41d8cd98f00b204e9800998ecf8427e', 474, false, false, '2022-11-26 05:39:57.335594', '2022-11-26 05:39:57.335594');");
    $chado->query("INSERT INTO {1:feature} (dbxref_id, organism_id, name, uniquename, residues, seqlen, md5checksum, type_id, is_analysis, is_obsolete, timeaccessioned, timelastmodified) VALUES (NULL, 1, 'Contig0', 'Contig0', '', 0, 'd41d8cd98f00b204e9800998ecf8427e', 474, false, false, '2022-11-26 05:39:59.809424', '2022-11-26 05:39:59.809424');");
    // $chado->query("INSERT INTO {1:feature} (dbxref_id, organism_id, name, uniquename, residues, seqlen, md5checksum, type_id, is_analysis, is_obsolete, timeaccessioned, timelastmodified) VALUES (NULL, 1, 'FRAEX38873_v2_000000010.1', 'FRAEX38873_v2_000000010.1', 'MDQNQFANELISSYFLQQWRHNSQTLTLNPTPSNSGTESDSARSDLEYEDEGEEFPTELDTVNSSGGFSVVGPGKLSVLYPNVNLHGHDVGVVHANCAAPSKRLLYYFEMYVKNAGAKGQIAIGFITSAFKVRRHPGWEANTYGYHGDDGLLYRGRGKGESFGPMYTTDDTKYTTGDTVGGGINYATQEFFFTKNGVVVGTVSKDVKSPVFPTVAVHSQGEEVTVNFGKDPFVFDIKAYEAEQRAIQQEKIDCISIPLDAGHGLVRSYLQHYGYEGTLEFFDMASKSTAPPISLVPENGFNEEDNVYAMNRRTLRELIRHGEIDETFAKLRELYPQIVQDDRSSICFLLHTQKFIELVRVGKLEEAVLYGRSEFEKFKRRSEFDDLVKDCAALLAYERPDNSSVGYLLRESQRELVADAVNAIILATNPNVKDPKCCLQSRLERLLRQLTACFLEKRSLNGGDGEAFHLRRILKSGKKG', 479, 'c5915348dc93ebb73a9bb17acfb29e84', 474, false, false, '2022-11-28 21:44:51.006276', '2022-11-28 21:44:51.006276');");
    // $chado->query("INSERT INTO {1:feature} (dbxref_id, organism_id, name, uniquename, residues, seqlen, md5checksum, type_id, is_analysis, is_obsolete, timeaccessioned, timelastmodified) VALUES (NULL, 1, 'FRAEX38873_v2_000000010.2', 'FRAEX38873_v2_000000010.2', 'MDQNQFANELISSYFLQQWRHNSQTLTLNPTPSNSGTESDSARSDLEYEDEGEEFPTELDTVNSSGGFSVVGPGKLSVLYPNVNLHGHDVGVVHANCAAPSKRLLYYFEMYVKNAGAKGQIAIGFITSAFKVRRHPGWEANTYGYHGDDGLLYRGRGKGESFGPMYTTDDTKYTTGDTVGGGINYATQEFFFTKNGVVVGTVSKDVKSPVFPTVAVHSQGEEVTVNFGKDPFVFDIKAYEAEQRAIQQEKIDCISIPLDAGHGLVRSYLQHYGYEGTLEFFDMASKSTAPPISLVPENGFNEEDNVYAMNRRTLRELIRHGEIDETFAKLRELYPQIVQDDRSSICFLLHTQKFIELVRVGKLEEAVLYGRSEFEKFKRRSEFDDLVKDCAALLAYERPDNSSVGYLLRESQRELVADAVNAIILATNPNVKDPKCCLQSRLERLLRQLTACFLEKRSLNGGDGEAFHLRRILKSGKKG', 479, 'c5915348dc93ebb73a9bb17acfb29e84', 474, false, false, '2022-11-28 21:44:51.006276', '2022-11-28 21:44:51.006276');");

    // // Test to ensure scaffold1 is found in the features table after landmarks loaded
    // $scaffold_query = $chado->query("SELECT count(*) as c1 FROM {1:feature}");
    // $scaffold_object = $scaffold_query->fetchObject();

    // print_r("Scaffold object\n");
    // print_r($scaffold_object);    


    // Perform the GFF3 test by creating an instance of the GFF3 loader
    $importer_manager = \Drupal::service('tripal.importer');
    $gff3_importer = $importer_manager->createInstance('chado_gff3_loader');
    $run_args = [
      'files' => [
        0 => [
          'file_path' => __DIR__ . '/../../../fixtures/gff3_loader/small_gene.gff'
        ]
      ],
      'schema_name' => $schema_name,
      'analysis_id' => $analysis_id,
      'organism_id' => $organism_id,
      'use_transaction' => 1,
      'add_only' => 0,
      'update' => 1,
      'create_organism' => 0,
      'create_target' => 0,
      // regexps for mRNA and protein.
      're_mrna' => NULL,
      're_protein' => NULL,
      // optional
      'target_organism_id' => NULL,
      'target_type' => NULL,
      'start_line' => NULL,
      'line_number' => NULL, // Previous error without this
      'landmark_type' => NULL,
      'alt_id_attr' => NULL,
      'skip_protein' => NULL,
    ];

    $file_details = [
      'file_local' => __DIR__ . '/../../../fixtures/gff3_loader/small_gene.gff',
    ];

    $gff3_importer->create($run_args, $file_details);
    $gff3_importer->prepareFiles();
    $gff3_importer->run();
    $gff3_importer->postRun();

    // This check determines if scaffold1 was added to the features table (this was done manually above)
    $results = $chado->query("SELECT * FROM {1:feature} WHERE uniquename='scaffold1';");
    $results_object = $results->fetchObject();
    $scaffold_feature_id = $results_object->feature_id;
    $this->assertEquals($results_object->uniquename, 'scaffold1');
    unset($results);
    unset($results_object);

    // This checks to ensure the test_gene_001 (gene) feature was inserted into the feature table
    $results = $chado->query("SELECT * FROM {1:feature} WHERE uniquename='test_gene_001';");
    $results_object = $results->fetchObject();
    $gene_feature_id = $results_object->feature_id;
    $this->assertEquals($results_object->uniquename, 'test_gene_001');
    unset($results);
    unset($results_object);

    // This checks to see whether the test_mrna_001.1 (mrna) feature got inserted into the feature table
    $results = $chado->query("SELECT * FROM {1:feature} WHERE uniquename='test_mrna_001.1';");
    $results_object = $results->fetchObject();
    $mrna_feature_id = $results_object->feature_id;
    $this->assertEquals($results_object->uniquename, 'test_mrna_001.1');
    unset($results);
    unset($results_object);

    // This checks to see whether the test_protein_001.1 (polypeptide) feature got inserted into the feature table
    $results = $chado->query("SELECT * FROM {1:feature} WHERE uniquename='test_protein_001.1';");
    $results_object = $results->fetchObject();
    $polypeptide_feature_id = $results_object->feature_id;
    $this->assertEquals($results_object->uniquename, 'test_protein_001.1');
    unset($results);
    unset($results_object);

    // Do checks on the featureprop table as well
    // Ensures the bio type value got added
    $results = $chado->query("SELECT * FROM {1:featureprop} WHERE feature_id = :feature_id AND value LIKE :value;", [
      ':feature_id' => $gene_feature_id,
      ':value' => 'protein_coding'
    ]);
    $has_exception = false;
    try {
      $results_object = $results->fetchObject();
    } catch (\Exception $ex) {
      $has_exception = true;
    }
    $this->assertEquals($has_exception, false, "biotype value was not added.");
    unset($results);
    unset($results_object);


    // Ensures the GAP value got added
    $results = $chado->query("SELECT * FROM {1:featureprop} WHERE feature_id = :feature_id AND value LIKE :value;", [
      ':feature_id' => $gene_feature_id,
      ':value' => 'test_gap_1'
    ]);
    $has_exception = false;
    try {
      $results_object = $results->fetchObject();
    } catch (\Exception $ex) {
      $has_exception = true;
    }
    $this->assertEquals($has_exception, false, "GAP value was not added.");
    unset($results);
    unset($results_object);

    // Ensures the NOTE value got added
    $results = $chado->query("SELECT * FROM {1:featureprop} WHERE feature_id = :feature_id AND value LIKE :value;", [
      ':feature_id' => $gene_feature_id,
      ':value' => 'test_gene_001_note'
    ]);
    $has_exception = false;
    try {
      $results_object = $results->fetchObject();
    } catch (\Exception $ex) {
      $has_exception = true;
    }
    $this->assertEquals($has_exception, false, "NOTE value was not added.");
    unset($results);
    unset($results_object);

    /**
     * Run the GFF loader on gff_duplicate_ids.gff for testing.
     *
     * This tests whether the GFF loader detects duplicate IDs which makes a 
     * GFF file invalid since IDs should be unique. The GFF loader should throw 
     * and exception which this test checks for
     */    
    // BEGIN NEW FILE: Perform import on gff_duplicate_ids
    $gff3_importer = $importer_manager->createInstance('chado_gff3_loader');
    $run_args = [
      'files' => [
        0 => [
          'file_path' => __DIR__ . '/../../../fixtures/gff3_loader/gff_duplicate_ids.gff'
        ]
      ],
      'schema_name' => $schema_name,
      'analysis_id' => $analysis_id,
      'organism_id' => $organism_id,
      'use_transaction' => 1,
      'add_only' => 0,
      'update' => 1,
      'create_organism' => 0,
      'create_target' => 0,
      // regexps for mRNA and protein.
      're_mrna' => NULL,
      're_protein' => NULL,
      // optional
      'target_organism_id' => NULL,
      'target_type' => NULL,
      'start_line' => NULL,
      'line_number' => NULL, // Previous error without this
      'landmark_type' => NULL,
      'alt_id_attr' => NULL,
      'skip_protein' => NULL,
    ];

    $file_details = [
      'file_local' => __DIR__ . '/../../../fixtures/gff3_loader/gff_duplicate_ids.gff',
    ];

    
    $has_exception = false;
    try {
      $gff3_importer->create($run_args, $file_details);
      $gff3_importer->prepareFiles();
      $gff3_importer->run();
      $gff3_importer->postRun();
    } catch (\Exception $ex) {
      $message = $ex->getMessage();
      $has_exception = true;
    }
    // TODO
    // $this->assertEquals($has_exception, true, "Duplicate ID was not detected and did not throw an error which it should have done.");

    /**
     * Run the GFF loader on gff_tag_unescaped_character.gff for testing.
     *
     * This tests whether the GFF loader adds IDs that contain a comma. 
     * The GFF loader should allow it
     */  
    // BEGIN NEW FILE: Perform import on gff_tag_unescaped_character
    $gff3_importer = $importer_manager->createInstance('chado_gff3_loader');
    $run_args = [
      'files' => [
        0 => [
          'file_path' => __DIR__ . '/../../../fixtures/gff3_loader/gff_tag_unescaped_character.gff'
        ]
      ],
      'schema_name' => $schema_name,
      'analysis_id' => $analysis_id,
      'organism_id' => $organism_id,
      'use_transaction' => 1,
      'add_only' => 0,
      'update' => 1,
      'create_organism' => 0,
      'create_target' => 0,
      // regexps for mRNA and protein.
      're_mrna' => NULL,
      're_protein' => NULL,
      // optional
      'target_organism_id' => NULL,
      'target_type' => NULL,
      'start_line' => NULL,
      'line_number' => NULL, // Previous error without this
      'landmark_type' => NULL,
      'alt_id_attr' => NULL,
      'skip_protein' => NULL,
    ];

    $file_details = [
      'file_local' => __DIR__ . '/../../../fixtures/gff3_loader/gff_tag_unescaped_character.gff',
    ];

    $has_exception = false;
    try {
      $gff3_importer->create($run_args, $file_details);
      $gff3_importer->prepareFiles();
      $gff3_importer->run();
      $gff3_importer->postRun();
    } catch (\Exception $ex) {
      $message = $ex->getMessage();
      $has_exception = true;
    }
    // TODO
    // $this->assertEquals($has_exception, true, "Should not have saved the unescaped character");

    /**
     * Run the GFF loader on gff_invalidstartend.gff for testing.
     *
     * This tests whether the GFF loader fixes start end values 
     */  
    // BEGIN NEW FILE: Perform import on gff_invalidstartend
    $gff3_importer = $importer_manager->createInstance('chado_gff3_loader');
    $run_args = [
      'files' => [
        0 => [
          'file_path' => __DIR__ . '/../../../fixtures/gff3_loader/gff_invalidstartend.gff'
        ]
      ],
      'schema_name' => $schema_name,
      'analysis_id' => $analysis_id,
      'organism_id' => $organism_id,
      'use_transaction' => 1,
      'add_only' => 0,
      'update' => 1,
      'create_organism' => 0,
      'create_target' => 0,
      // regexps for mRNA and protein.
      're_mrna' => NULL,
      're_protein' => NULL,
      // optional
      'target_organism_id' => NULL,
      'target_type' => NULL,
      'start_line' => NULL,
      'line_number' => NULL, // Previous error without this
      'landmark_type' => NULL,
      'alt_id_attr' => NULL,
      'skip_protein' => NULL,
    ];

    $file_details = [
      'file_local' => __DIR__ . '/../../../fixtures/gff3_loader/gff_invalidstartend.gff',
    ];

    $has_exception = false;
    try {
      $gff3_importer->create($run_args, $file_details);
      $gff3_importer->prepareFiles();
      $gff3_importer->run();
      $gff3_importer->postRun();
    } catch (\Exception $ex) {
      $message = $ex->getMessage();
      $has_exception = true;
    }
    // TODO
    // $this->assertEquals($has_exception, true, "Should not complete when there is invalid start and end values but did throw error.");

    /**
     * Run the GFF loader on gff_phase_invalid_character.gff for testing.
     *
     * This tests whether the GFF loader interprets the phase values correctly
     * for CDS rows when a character outside of the range 0,1,2 is specified.
     */
    // BEGIN NEW FILE: Perform import on gff_phase_invalid_character
    $gff3_importer = $importer_manager->createInstance('chado_gff3_loader');
    $run_args = [
      'files' => [
        0 => [
          'file_path' => __DIR__ . '/../../../fixtures/gff3_loader/gff_phase_invalid_character.gff'
        ]
      ],
      'schema_name' => $schema_name,
      'analysis_id' => $analysis_id,
      'organism_id' => $organism_id,
      'use_transaction' => 1,
      'add_only' => 0,
      'update' => 1,
      'create_organism' => 0,
      'create_target' => 0,
      // regexps for mRNA and protein.
      're_mrna' => NULL,
      're_protein' => NULL,
      // optional
      'target_organism_id' => NULL,
      'target_type' => NULL,
      'start_line' => NULL,
      'line_number' => NULL, // Previous error without this
      'landmark_type' => NULL,
      'alt_id_attr' => NULL,
      'skip_protein' => NULL,
    ];

    $file_details = [
      'file_local' => __DIR__ . '/../../../fixtures/gff3_loader/gff_phase_invalid_character.gff',
    ];

    $has_exception = false;
    try {
      $gff3_importer->create($run_args, $file_details);
      $gff3_importer->prepareFiles();
      $gff3_importer->run();
      $gff3_importer->postRun();
    } catch (\Exception $ex) {
      $message = $ex->getMessage();
      $has_exception = true;
    }
    $this->assertEquals($has_exception, true, "Should not complete when there is invalid phase value (in this case character a) but did throw error.");

    /**
     * Run the GFF loader on gff_phase_invalid_number.gff for testing.
     *
     * This tests whether the GFF loader interprets the phase values correctly
     * for CDS rows when a number outside of the range 0,1,2 is specified.
     */ 
    // BEGIN NEW FILE: Perform import on gff_phase_invalid_number
    $gff3_importer = $importer_manager->createInstance('chado_gff3_loader');
    $run_args = [
      'files' => [
        0 => [
          'file_path' => __DIR__ . '/../../../fixtures/gff3_loader/gff_phase_invalid_number.gff'
        ]
      ],
      'schema_name' => $schema_name,
      'analysis_id' => $analysis_id,
      'organism_id' => $organism_id,
      'use_transaction' => 1,
      'add_only' => 0,
      'update' => 1,
      'create_organism' => 0,
      'create_target' => 0,
      // regexps for mRNA and protein.
      're_mrna' => NULL,
      're_protein' => NULL,
      // optional
      'target_organism_id' => NULL,
      'target_type' => NULL,
      'start_line' => NULL,
      'line_number' => NULL, // Previous error without this
      'landmark_type' => NULL,
      'alt_id_attr' => NULL,
      'skip_protein' => NULL,
    ];

    $file_details = [
      'file_local' => __DIR__ . '/../../../fixtures/gff3_loader/gff_phase_invalid_number.gff',
    ];

    $has_exception = false;
    try {
      $gff3_importer->create($run_args, $file_details);
      $gff3_importer->prepareFiles();
      $gff3_importer->run();
      $gff3_importer->postRun();
    } catch (\Exception $ex) {
      $message = $ex->getMessage();
      $has_exception = true;
    }
    $this->assertEquals($has_exception, true, "Should not complete when there is invalid phase value (in this case a number) but did throw error.");

    
    /**
     * Test that when checked, explicit proteins are created when specified within
     * the GFF file. Explicit proteins will not respect the skip_protein argument
     * and will therefore be added to the database.
    */
    // BEGIN NEW FILE: Perform import on gff_phase
    $gff3_importer = $importer_manager->createInstance('chado_gff3_loader');
    $run_args = [
      'files' => [
        0 => [
          'file_path' => __DIR__ . '/../../../fixtures/gff3_loader/gff_phase.gff'
        ]
      ],
      'schema_name' => $schema_name,
      'analysis_id' => $analysis_id,
      'organism_id' => $organism_id,
      'use_transaction' => 1,
      'add_only' => 0,
      'update' => 1,
      'create_organism' => 0,
      'create_target' => 0,
      // regexps for mRNA and protein.
      're_mrna' => NULL,
      're_protein' => NULL,
      // optional
      'target_organism_id' => NULL,
      'target_type' => NULL,
      'start_line' => NULL,
      'line_number' => NULL, // Previous error without this
      'landmark_type' => NULL,
      'alt_id_attr' => NULL,
      'skip_protein' => NULL,
    ];

    $file_details = [
      'file_local' => __DIR__ . '/../../../fixtures/gff3_loader/gff_phase.gff',
    ];

    $has_exception = false;
    try {
      $gff3_importer->create($run_args, $file_details);
      $gff3_importer->prepareFiles();
      $gff3_importer->run();
      $gff3_importer->postRun();
    } catch (\Exception $ex) {
      $message = $ex->getMessage();
      $has_exception = true;
    }
    // TODO
    // $this->assertEquals($has_exception, false, "This is a valid phase file that should not produce an exception but did.");


    // $results = $chado->query("SELECT * FROM {1:featureprop};");
    // while ($object = $results->fetchObject()) {
    //   print_r($object);
    // }

  }
}