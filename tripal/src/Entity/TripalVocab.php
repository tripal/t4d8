<?php

namespace Drupal\tripal\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\tripal\Entity\TripalVocabInterface;

/**
 * Defines the Controlled Vocabulary entity.
 *
 * @ingroup tripal
 *
 * @ContentEntityType(
 *   id = "tripal_vocab",
 *   label = @Translation("Tripal Vocabulary"),
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\tripal\ListBuilders\TripalVocabListBuilder",
 *     "views_data" = "Drupal\tripal\Entity\TripalVocabViewsData",
 *
 *     "form" = {
 *       "default" = "Drupal\tripal\Form\TripalVocabForm",
 *       "add" = "Drupal\tripal\Form\TripalVocabForm",
 *       "edit" = "Drupal\tripal\Form\TripalVocabForm",
 *       "delete" = "Drupal\tripal\Form\TripalVocabDeleteForm",
 *     },
 *     "access" = "Drupal\tripal\Access\TripalVocabAccessControlHandler",
 *     "route_provider" = {
 *       "html" = "Drupal\tripal\TripalVocabHtmlRouteProvider",
 *     },
 *   },
 *   base_table = "tripal_vocab",
 *   translatable = FALSE,
 *   admin_permission = "administer controlled vocabulary entities",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "name",
 *   },
 *   links = {
 *     "canonical" = "/admin/structure/tripal-vocabularies/vocab/{tripal_vocab}",
 *     "add-form" = "/admin/structure/tripal-vocabularies/vocab/add",
 *     "edit-form" = "/admin/structure/tripal-vocabularies/vocab/{tripal_vocab}/edit",
 *     "delete-form" = "/admin/structure/tripal-vocabularies/vocab/{tripal_vocab}/delete",
 *     "collection" = "/admin/structure/tripal-vocabularies/vocab",
 *   },
 * )
 */
class TripalVocab extends ContentEntityBase implements TripalVocabInterface {

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
      $instance->preSaveVocab($this, $storage);
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
      $instance->postSaveVocab($this, $storage, $update);
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
        $instance->loadVocab($id, $entities[$id]);
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
      $instance->deleteVocab($this);
    }

    parent::delete();
  }

  /**
   * {@inheritdoc}
   */
  public function getID() {
    return $this->get('id')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel() {
    return $this->get('name')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return $this->get('name')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setName($name) {
    $this->set('name', $name);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getNamespace() {
    return $this->get('namespace')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setNamespace($namespace) {
    $this->set('namespace', $namespace);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->get('description')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setDescription($description) {
    $this->set('description', $description);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getURL() {
    return $this->get('url')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setURL($url) {
    $this->set('url', $url);
    return $this;
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
  public function getChangedTime() {
    return $this->get('changed')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setChangedTime($timestamp) {
    $this->set('changed', $timestamp);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getChangedTimeAcrossTranslations() {
    return $this->get('changed')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getNumberofTerms() {
    // @todo implement this ;-p.
    return 0;
  }

  /**
   * {@inheritdoc}
   */
  public function getDetails() {
    $details = [];

    $details['TripalVocab'] = $this;
    $details['name'] = $this->getName();
    $details['namespace'] = $this->getNamespace();
    $details['description'] = $this->getDescription();
    $details['URL'] = $this->getURL();
    $vocabulary['num_terms'] = $this->getNumberofTerms();

    return $details;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Vocabulary Name'))
      ->setDescription(t('The full name for the vocabulary (e.g. sequence).'))
      ->setSettings(array(
        'max_length' => 1024,
        'text_processing' => 0,
      ));

    $fields['namespace'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Vocabulary Namespace'))
      ->setDescription(t('The namespace for the vocabulary (e.g. sequence).'))
      ->setSettings(array(
        'max_length' => 1024,
        'text_processing' => 0,
      ));

    $fields['description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Vocabulary Description'))
      ->setDescription(t('A description for the vocabulary.'))
      ->setSettings(array(
        'text_processing' => 0,
      ));

    $fields['url'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Vocabulary URL'))
      ->setDescription(t('The URL providing a reference for this vocabulary.'))
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
