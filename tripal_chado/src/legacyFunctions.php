<?php

use Drupal\tripal\Services\TripalJob;

/**
 * Submit Load Chado Schema Form
 *
 * @ingroup tripal_chado
 */
function tripal_chado_load_drush_submit($action, $chado_schema = 'chado') {

  if ($action == \Drupal\tripal_chado\Form\ChadoInstallForm::INSTALL_13_ACTION) {
    $installer = \Drupal::service('tripal_chado.chadoInstaller');
    $installer->setSchema($chado_schema);
    $success = $installer->install(1.3);
  }
  else {
    \Drupal::logger('tripal_chado')->error("NOT SUPPORTED: " . $action);
  }
}

/**
 * Install Chado Schema
 *
 * @ingroup tripal_chado
 */
function tripal_chado_install_chado($action, $chado_schema = 'chado', $job = NULL) {

  if ($action == \Drupal\tripal_chado\Form\ChadoInstallForm::INSTALL_13_ACTION) {
    $installer = \Drupal::service('tripal_chado.chadoInstaller');
    $installer->setSchema($chado_schema);
    if ($job) {
      $installer->setJob($job);
    }
    $success = $installer->install(1.3);
  }
  else {
    \Drupal::logger('tripal_chado')->error("NOT SUPPORTED: " . $action);
  }
}

/**
 * Integrate Chado Schema.
 *
 * @ingroup tripal_chado
 */
function tripal_chado_integrate_chado($chado_schema = 'chado', $job = NULL) {
  if ($chado_schema) {
    \Drupal::service('tripal_chado.chadoIntegrator')->import($chado_schema);
  }
  else {
    \Drupal::logger('tripal_chado')->error("No schema was provided. Nothing to integrate.");
  }
}

/**
 * Clone Chado Schema.
 *
 * @ingroup tripal_chado
 */
function tripal_chado_clone_schema($source_chado_schema, $target_chado_schema, $job = NULL) {
  $cloner = \Drupal::service('tripal_chado.chadoCloner');
  $cloner->setSchema($target_chado_schema);
  if ($job) {
    $cloner->setJob($job);
  }
  $success = $cloner->cloneSchema($source_chado_schema);
}

/**
 * Drop Chado Schema
 *
 * @ingroup tripal_chado
 */
function tripal_chado_drop_schema($schema, $job = NULL) {
  if ($schema) {
    \Drupal::service('tripal.bulkPgSchemaInstaller')->dropSchema($schema);
  }
  else {
    \Drupal::logger('tripal_chado')->error("No schema was provided. Cannot drop.");
  }
}

/**
 * Upgrade Chado Schema
 *
 * @ingroup tripal_chado
 */
function tripal_chado_upgrade_schema($action, $chado_schema = 'chado', $cleanup = TRUE, $file = NULL, $job = NULL) {

  if ($action == \Drupal\tripal_chado\Form\ChadoInstallForm::UPGRADE_13_ACTION) {
    $upgrader = \Drupal::service('tripal_chado.chadoUpgrader');
    $upgrader->setSchema($chado_schema);
    if ($job) {
      $upgrader->setJob($job);
    }
    $success = $upgrader->upgrade($chado_schema, '1.3', $cleanup, $file);
  }
  else {
    \Drupal::logger('tripal_chado')->error("NOT SUPPORTED: " . $action);
  }

/**
 * Prepare Chado Schema
 *
 * @ingroup tripal_chado
 */
function tripal_chado_prepare_chado($chado_schema = 'chado', $job = NULL) {
  $preparer = \Drupal::service('tripal_chado.chadoPreparer');
  if ($job) {
    $preparer->setJob($job);
  }
  $preparer->setSchema($chado_schema);
  $success = $preparer->prepare();
}
