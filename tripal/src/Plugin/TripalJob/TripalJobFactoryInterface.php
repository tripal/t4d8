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

  /**
   * List Tripal Jobs.
   *
   * List all tripal jobs of this TripalJobFactory plugin type with the given status. If an empty
   * string is given for status then all jobs are returned. See the TripalJobInterface for the valid
   * set of status strings.
   *
   * @param string $status
   *   The status of TripalJobInterface instances returned or an empty string to return all
   *   instances.
   * @return array
   *   An array of TripalJobInterface instances.
   */
  public function list($status);
}
