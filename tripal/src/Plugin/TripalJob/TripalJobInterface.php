<?php

namespace Drupal\tripal\Plugin\TripalJob;

use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * Defines an interface for Tripal Jobs. This extends the plugin inspection
 * interface to provide information about its plugin factory.
 */
interface TripalJobFactoryInterface extends PluginInspectionInterface {

  /**
   * Executes this Job.
   *
   * Executes this Tripal Job, returning once the job is complete. This could
   * take a very long time so do not run it where the user is expecting a response right away.
   */
  public function execute();
}
