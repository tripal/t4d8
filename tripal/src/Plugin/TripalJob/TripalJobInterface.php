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

  /**
   * Get job status.
   *
   * Get the status of this Tripal job.
   *
   * @return string
   *   The current status of this job. Valid status strings are Waiting, Completed, Running,
   *   Cancelled, or Error.
   */
  public function status();

  /**
   * Get start time.
   *
   * Get the start time of this Tripal job.
   *
   * @return int
   *   The start time of this job measured in the number of seconds since the Unix Epoch
   *   (January 1 1970 00:00:00 GMT).
   */
  public function startTime();

  /**
   * Get end time.
   *
   * Get the end time of this Tripal job.
   *
   * @return int
   *   The end time of this job measured in the number of seconds since the Unix Epoch
   *   (January 1 1970 00:00:00 GMT).
   */
  public function endTime();

  /**
   * Get job progress.
   *
   * Get the progress of this Tripal job.
   *
   * @return int
   *   The progress of this job. This must be in the range of 0 to 100, where 0 is no progress and
   *   100 is complete progress.
   */
  public function endTime();

  /**
   * Get job user.
   *
   * Get the user that created this Tripal Job.
   *
   * @return string
   *   User that created this job.
   */
  public function endTime();
}
