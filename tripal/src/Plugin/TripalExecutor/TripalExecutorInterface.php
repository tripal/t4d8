<?php

namespace Drupal\tripal\Plugin\TripalJob;

use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * Defines an interface for Tripal Executor plugins.
 */
interface TripalExecutorInterface extends PluginInspectionInterface {

  /**
   * Adds a Tripal Job.
   *
   * Adds the given Tripal Job to the queue of jobs to execute.
   *
   * @param \Drupal\tripal\Plugin\TripalJob\TripalJobInterface $definition
   *   The Tripal Job that is saved in this executor to be executed later.
   */
  public function addJob($job);

  /**
   * Executes all jobs.
   *
   * Executes all queued Tripal Jobs added to this executor. This is blocking
   * and should not be called where the user is expecting a response right away.
   */
  public function executeAll();
}
