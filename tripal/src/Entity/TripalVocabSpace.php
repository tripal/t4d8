<?php

namespace Drupal\tripal\Entity;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;

/**
 * Defines the Tripal Vocabulary IDSpace entity.
 *
 * @ingroup tripal
 *
 * @ContentEntityType(
 *   id = "tripal_vocab_space",
 *   label = @Translation("Tripal Vocabulary IDSpace"),
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\tripal\ListBuilders\TripalVocabSpaceListBuilder",
 *     "views_data" = "Drupal\tripal\Entity\TripalVocabSpaceViewsData",
 *
 *     "form" = {
 *       "default" = "Drupal\tripal\Form\TripalVocabSpaceForm",
 *       "add" = "Drupal\tripal\Form\TripalVocabSpaceForm",
 *       "edit" = "Drupal\tripal\Form\TripalVocabSpaceForm",
 *       "delete" = "Drupal\tripal\Form\TripalVocabSpaceDeleteForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\tripal\TripalVocabSpaceHtmlRouteProvider",
 *     },
 *     "access" = "Drupal\tripal\Access\TripalVocabSpaceAccessControlHandler",
 *   },
 *   base_table = "tripal_vocab_space",
 *   translatable = FALSE,
 *   admin_permission = "administer tripal vocabulary idspace entities",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "IDSpace",
 *   },
 *   links = {
 *     "canonical" = "/admin/structure/tripal-vocabularies/idspace/{tripal_vocab_space}",
 *     "add-form" = "/admin/structure/tripal-vocabularies/idspace/add",
 *     "edit-form" = "/admin/structure/tripal-vocabularies/idspace/{tripal_vocab_space}/edit",
 *     "delete-form" = "/admin/structure/tripal-vocabularies/idspace/{tripal_vocab_space}/delete",
 *     "collection" = "/admin/structure/tripal-vocabularies/idspace",
 *   },
 * )
 */
class TripalVocabSpace extends ContentEntityBase implements TripalVocabSpaceInterface {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);

    // Use the plugin manager to get a list of all implementations
    // of our TripalTermStorage plugin.
    $manager = \Drupal::service('plugin.manager.tripal.termStorage');
    $implementations = $manager->getDefinitions();

    // Then foreach implementation we want to create an instance of
    // that particular term storage plugin and call the appropriate method.
    foreach (array_keys($implementations) as $instance_id) {
      $instance = $manager->createInstance($instance_id);
      $instance->preSaveVocabSpace($this, $storage);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE) {
    parent::postSave($storage, $update);

    // Use the plugin manager to get a list of all implementations
    // of our TripalTermStorage plugin.
    $manager = \Drupal::service('plugin.manager.tripal.termStorage');
    $implementations = $manager->getDefinitions();

    // Then foreach implementation we want to create an instance of
    // that particular term storage plugin and call the appropriate method.
    foreach (array_keys($implementations) as $instance_id) {
      $instance = $manager->createInstance($instance_id);
      $instance->postSaveVocabSpace($this, $storage, $update);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function postLoad(EntityStorageInterface $storage, array &$entities) {
    parent::postLoad($storage, $entities);

    // Use the plugin manager to get a list of all implementations
    // of our TripalTermStorage plugin.
    $manager = \Drupal::service('plugin.manager.tripal.termStorage');
    $implementations = $manager->getDefinitions();

    // Then foreach implementation we want to create an instance of
    // that particular term storage plugin and call the appropriate method.
    foreach (array_keys($implementations) as $instance_id) {
      $instance = $manager->createInstance($instance_id);
      foreach ($entities as $id => $entity) {
        $instance->loadVocabSpace($id, $entities[$id]);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delete() {

    // Use the plugin manager to get a list of all implementations
    // of our TripalTermStorage plugin.
    $manager = \Drupal::service('plugin.manager.tripal.termStorage');
    $implementations = $manager->getDefinitions();

    // Then foreach implementation we want to create an instance of
    // that particular term storage plugin and call the appropriate method.
    foreach (array_keys($implementations) as $instance_id) {
      $instance = $manager->createInstance($instance_id);
      $instance->deleteVocabSpace($this);
    }

    parent::delete();
  }

  /**
   * {@inheritdoc}
   */
  public function getIDSpace() {
    return $this->get('IDSpace')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setIDSpace($idspace) {
    $this->set('IDSpace', $idspace);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getURLPrefix() {
    return $this->get('URLprefix')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setURLPrefix($URLprefix) {
    $this->set('URLprefix', $URLprefix);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getVocabID(){
    return $this->get('vocab_id')->getString();
  }

  /**
   * {@inheritdoc}
   */
  public function setVocabID($vocab_id) {
    $this->set('vocab_id', $vocab_id);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getVocab() {
    $vocab_id = $this->getVocabID();
    $vocab = TripalVocab::load($vocab_id);
    return $vocab;
  }

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime() {
    return $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setCreatedTime($timestamp) {
    $this->set('created', $timestamp);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['IDSpace'] = BaseFieldDefinition::create('string')
      ->setLabel(t('IDSpace'))
      ->setDescription(t('The IDSpace of the vocabulary (e.g. SO).'))
      ->setSettings([
        'max_length' => 50,
        'text_processing' => 0,
      ])
      ->setRequired(TRUE);

    $fields['vocab_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Namespace'))
      ->setDescription(t('The vocabulary which originally coined this IDSpace. You can think of this as the default vocabulary for this IDSpace (e.g. sequence for SO).'))
      ->setSetting('target_type', 'tripal_vocab');

    $fields['URLprefix'] = BaseFieldDefinition::create('string')
      ->setLabel(t('URL Prefix'))
      ->setDescription(t('A URL to access the term of this IDSpace. It can include the {{IDSpace}} and {{accession}} tokens.'))
      ->setSettings(array(
        'max_length' => 1024,
        'text_processing' => 0,
      ));

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time that the entity was created.'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the entity was last edited.'));

    return $fields;
  }

}
