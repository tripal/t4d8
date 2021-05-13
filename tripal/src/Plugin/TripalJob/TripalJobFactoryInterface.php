<?php

namespace Drupal\tripal\Plugin\TripalJob;

use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * Defines an interface for Tripal Job Factory plugins.
 */
interface TripalJobFactoryInterface extends PluginInspectionInterface {

  /**
   * Creates a new Tripal Job.
   *
   * Creates a new Tripal Job from the given definition array.
   *
   * @param array $definition
   *   The definition which contains all data required to create a new Tripal
   *   Job.
   * @return \Drupal\tripal\Plugin\TripalJob\TripalJobInterface
   *   An instance of a new Tripal Job created from the given definition.
   */
  public function create($definition);

  /**
   * Loads a Tripal Job.
   *
   * Loads an already created Tripal Job.
   *
   * @param int $id
   *   A unique ID of an already created Tripal Job.
   * @return \Drupal\tripal\Plugin\TripalJob\TripalJobInterface
   *   An instance of an already created Tripal Job.
   */
  public function load($id);
}
