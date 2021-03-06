<?php

namespace Drupal\tripal_chado\Plugin\TripalTermStorage;

use Drupal\tripal\Entity\TripalVocab;
use Drupal\tripal\Entity\TripalVocabSpace;
use Drupal\tripal\Entity\TripalTerm;

use Drupal\tripal\Plugin\TripalTermStorage\TripalTermStorageBase;
use Drupal\tripal\Plugin\TripalTermStorage\TripalTermStorageInterface;
use Drupal\Core\Entity\EntityStorageInterface;

/**
 * TripalTerm Storage plugin: Chado Integration.
 *
 * @ingroup tripal_chado
 *
 * @TripalTermStorage(
 *   id = "chado",
 *   label = @Translation("GMOD Chado Integration"),
 *   description = @Translation("Ensures Tripal Vocabularies are linked with chado cvterms."),
 * )
 */
class TripalTermStorageChado extends TripalTermStorageBase implements TripalTermStorageInterface {

  /**
   * {@inheritdoc}
   */
  public function postSaveVocab(TripalVocab &$entity, EntityStorageInterface $storage, $update) {

    // Figure out the chado schema name.
    // @todo find a better way to do this obviously.
    $chado_schema_name = 'chado';

    // Get the TripalVocab ID.
    $tripalvocab_id = $entity->get('id')->value;

    // Get the chado cv_id.
    $chadocv['name'] = $entity->getNamespace();
    $chadocv['definition'] = $entity->getName();

    $exists = chado_select_record('cv', ['cv_id'], $chadocv, [], $chado_schema_name);
    if ($exists) {
      $cv_id = $exists[0]->cv_id;
    }
    else {
      // Because we already checked uniqueness with the select above,
      // we don't need to do validation for the insert. This is added
      // to help performance.
      $options['skip_validation'] = TRUE;
      $cv = chado_insert_record('cv', $chadocv, $options, $chado_schema_name);
      if ($cv) {
        $cv_id = $cv['cv_id'];
      }
      else {
        return FALSE;
      }
    }

    // Finally, we need to link the records.
    $connection = \Drupal::service('database');
    $result = $connection->merge('chado_tripalvocab')
      ->key('tripalvocab_id', $tripalvocab_id)
      ->fields([
        'schema_name' => $chado_schema_name,
        'cv_id' => $cv_id,
      ])
      ->execute();

  }

  /**
   * {@inheritdoc}
   */
  public function postSaveVocabSpace(TripalVocabSpace &$entity, EntityStorageInterface $storage, $update) {

    // Figure out the chado schema name.
    // @todo find a better way to do this obviously.
    $chado_schema_name = 'chado';

    // Get the TripalVocabSpace ID.
    $tripalvocabspace_id = $entity->get('id')->value;

    // Grab the default Tripal Vocabulary for the url/description.
    $tripalvocab = $entity->getVocab();

    // Get the chado db_id.
    $chadodb['name'] = $entity->getIDSpace();
    $chadodb['description'] = $tripalvocab->getDescription();
    $chadodb['url'] = $tripalvocab->getURL();
    $chadodb['urlprefix'] = $entity->getURLPrefix();

    $chadodbselect = [ 'name' => $chadodb['name'] ];
    $exists = chado_select_record('db', ['db_id'], $chadodbselect, [], $chado_schema_name);
    if ($exists) {
      $db_id = $exists[0]->db_id;
    }
    else {
      // Because we already checked uniqueness with the select above,
      // we don't need to do validation for the insert. This is added
      // to help performance.
      $options['skip_validation'] = TRUE;
      $db = chado_insert_record('db', $chadodb, $options, $chado_schema_name);
      if ($db) {
        $db_id = $db['db_id'];
      }
      else {
        return FALSE;
      }
    }

    // Finally, we need to link the records.
    $connection = \Drupal::service('database');
    $result = $connection->merge('chado_tripalvocabspace')
      ->key('tripalvocabspace_id', $tripalvocabspace_id)
      ->fields([
        'schema_name' => $chado_schema_name,
        'db_id' => $db_id,
      ])
      ->execute();

  }

  /**
   * {@inheritdoc}
   */
  public function postSaveTerm(TripalTerm &$entity, EntityStorageInterface $storage, $update) {

    // Figure out the chado schema name.
    // @todo find a better way to do this obviously.
    $chado_schema_name = 'chado';

    // Get the TripalTerm ID.
    $tripalterm_id = $entity->get('id')->value;

    // Get the Tripal Vocab and IDSpace.
    $idspace = $entity->getIDSpace();
    // We use the IDSpace to get the default vocabulary since chado can
    // only support a single cv for a given cvterm.
    $vocab = $idspace->getVocab();

    // Get the chado cvterm_id.
    $cvterm_accession = $entity->getAccession();
    $chadocvterm['db_name'] = $idspace->getIDSpace();
    $chadocvterm['id'] = $chadocvterm['db_name'] . ':' . $cvterm_accession;
    $chadocvterm['name'] = $entity->getName();
    $chadocvterm['definition'] = $entity->getDefinition();
    $chadocvterm['cv_name'] = $vocab->getNamespace();

    $dbxrefcheck = [ ':db_name' => $chadocvterm['db_name'], ':accession' => $cvterm_accession];
    $exists = chado_query('SELECT cvterm_id FROM {cvterm} cvt
      LEFT JOIN {dbxref} dbx ON dbx.dbxref_id=cvt.dbxref_id
      LEFT JOIN {db} db ON db.db_id=dbx.db_id
      WHERE db.name = :db_name AND dbx.accession = :accession',
      $dbxrefcheck, [] , $chado_schema_name)->fetchField();
    if ($exists) {
      $cvterm_id = $exists;
    }
    else {
      $cvterm = chado_insert_cvterm($chadocvterm, [], $chado_schema_name);
      if ($cvterm) {
        $cvterm_id = $cvterm->cvterm_id;
      }
      else {
        return FALSE;
      }
    }

    // Finally, we need to link the records.
    $connection = \Drupal::service('database');
    $result = $connection->merge('chado_tripalterm')
      ->key('tripalterm_id', $tripalterm_id)
      ->fields([
        'schema_name' => $chado_schema_name,
        'cvterm_id' => $cvterm_id,
      ])
      ->execute();

  }

  /**
   * {@inheritdoc}
   */
  public function deleteVocab(TripalVocab $entity) {

    // We are not deleting from Chado!
    // As such, the chado cv will remain even if we created it.

    // We do want to delete the linking record though.
    $id = $entity->get('id')->value;
    $connection = \Drupal::service('database');
    $result = $connection->delete('chado_tripalvocab')
      ->condition('tripalvocab_id', $id)
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function deleteVocabSpace(TripalVocabSpace $entity) {

    // We are not deleting from Chado!
    // As such, the chado db will remain even if we created it.

    // We do want to delete the linking record though.
    $id = $entity->get('id')->value;
    $connection = \Drupal::service('database');
    $result = $connection->delete('chado_tripalvocabspace')
      ->condition('tripalvocabspace_id', $id)
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function deleteTerm(TripalTerm $entity) {

    // We are not deleting from Chado!
    // As such, the chado cvterm/dbxref will remain even if we created it.

    // We do want to delete the linking record though.
    $id = $entity->get('id')->value;
    $connection = \Drupal::service('database');
    $result = $connection->delete('chado_tripalterm')
      ->condition('tripalterm_id', $id)
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function loadVocab($id, TripalVocab &$entity) {

    // Select the linking between this entity and chado.
    $connection = \Drupal::service('database');
    $chado_deets = $connection->select('chado_tripalvocab', 't')
      ->fields('t', ['schema_name', 'cv_id'])
      ->condition('tripalvocab_id', $id)
      ->execute()
      ->fetchObject();

    // Add in the chado details for the current record.
    $select = ['cv_id' => $chado_deets->cv_id];
    $options = ['include_fk' => []];
    $entity->chado_record = chado_generate_var('cv', $select, $options, $chado_deets->schema_name);
    $entity->chado_record->tablename = 'cv';
    $entity->chado_record->entity_id = $id;

    // Add in the chado primary key for easy access.
    $entity->chado_record_id = $chado_deets->cv_id;

    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function loadVocabSpace($id, TripalVocabSpace &$entity) {

    // Select the linking between this entity and chado.
    $connection = \Drupal::service('database');
    $chado_deets = $connection->select('chado_tripalvocabspace', 't')
      ->fields('t', ['schema_name', 'db_id'])
      ->condition('tripalvocabspace_id', $id)
      ->execute()
      ->fetchObject();

    // Add in the chado details for the current record.
    $select = ['db_id' => $chado_deets->db_id];
    $options = ['include_fk' => []];
    $entity->chado_record = chado_generate_var('db', $select, $options, $chado_deets->schema_name);
    $entity->chado_record->tablename = 'db';
    $entity->chado_record->entity_id = $id;

    // Add in the chado primary key for easy access.
    $entity->chado_record_id = $chado_deets->db_id;

    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function loadTerm($id, TripalTerm &$entity) {

    // Select the linking between this entity and chado.
    $connection = \Drupal::service('database');
    $chado_deets = $connection->select('chado_tripalterm', 't')
      ->fields('t', ['schema_name', 'cvterm_id'])
      ->condition('tripalterm_id', $id)
      ->execute()
      ->fetchObject();

    // Add in the chado details for the current record.
    $select = ['cvterm_id' => $chado_deets->cvterm_id];
    $options = ['include_fk' => []];
    $entity->chado_record = chado_generate_var('cvterm', $select, $options, $chado_deets->schema_name);
    $entity->chado_record->tablename = 'cvterm';
    $entity->chado_record->entity_id = $id;

    // Add in the chado primary key for easy access.
    $entity->chado_record_id = $chado_deets->cvterm_id;

    return $entity;
  }
}
