<?php

namespace Drupal\tripal\Plugin\TripalJob;

use Drupal\tripal\Plugin\TripalJob\TripalJobInterface;
use Drupal\Component\Plugin\PluginBase;

/**
 * Base class for Tripal Job plugins.
 */
interface TripalJobBase implements TripalJobInterface {

  /**
   * @see Drupal\Component\Plugin\PluginInspectionInterface
   */
  public function getPluginId() {
    return $this->_pluginId;
  }

  /**
   * @see Drupal\Component\Plugin\PluginInspectionInterface
   */
  public function getPluginDefinition() {
    return $this->_pluginDefinition;
  }

  /**
   * Constructs new Tripal Job.
   *
   * Constructs this new Tripal Job with the given factory.
   *
   * @param \Drupal\tripal\Plugin\TripalJob\TripalJobFactoryInterface factory
   *   The Tripal Job Factory that created this new instance.
   */
  protected function __construct($factory) {
    $this->_pluginId = $factory->getPluginId();
    $this->_pluginDefinition = $factory->getPluginDefinition();
  }

  /**
   * @see \Drupal\tripal\Plugin\TripalJob\TripalJobInterface
   */
  public function getJobID() {
    return $this->_jobID;
  }

  /**
   * @see \Drupal\tripal\Plugin\TripalJob\TripalJobInterface
   */
  public function getStatus() {
    return $this->_status;
  }

  /**
   * @see \Drupal\tripal\Plugin\TripalJob\TripalJobInterface
   */
  public function getStartTime() {
    return $this->_startTime;
  }

  /**
   * @see \Drupal\tripal\Plugin\TripalJob\TripalJobInterface
   */
  public function getEndTime() {
    return $this->_endTime;
  }

  /**
   * @see \Drupal\tripal\Plugin\TripalJob\TripalJobInterface
   */
  public function getProgress() {
    return $this->_progress;
  }

  /**
   * @see \Drupal\tripal\Plugin\TripalJob\TripalJobInterface
   */
  public function getUser() {
    return $this->_user;
  }

  /**
   * Sets this job's ID.
   *
   * Sets the ID of this callback tripal job to the given value.
   *
   * @param int id
   *   The job ID us of this job. This must only be set once since this never changes.
   *
   * @see \Drupal\tripal\Plugin\TripalJob\TripalJobInterface
   */
  protected function setJobID($id) {
    $this->_jobID = $id;
  }

  /**
   * Sets this job's status.
   *
   * Sets the status of this callback tripal job to the given status.
   *
   * @param string status
   *   The new status of this job. This must be one of the legal string status values.
   *
   * @see \Drupal\tripal\Plugin\TripalJob\TripalJobInterface
   */
  protected function setStatus($status) {
    $this->_status = $status;
  }

  /**
   * Sets this job's start time.
   *
   * Sets the start time of this callback tripal job to the given time.
   *
   * @param int t
   *   The time this callback tripal job started.
   *
   * @see \Drupal\tripal\Plugin\TripalJob\TripalJobInterface
   */
  protected function setStartTime($t) {
    $this->_startTime = $t;
  }

  /**
   * Sets this job's end time.
   *
   * Sets the end time of this callback tripal job to the given time.
   *
   * @param int t
   *   The time this callback tripal job finished.
   *
   * @see \Drupal\tripal\Plugin\TripalJob\TripalJobInterface
   */
  protected function setEndTime($t) {
    $this->_endTime = $t;
  }

  /**
   * Sets this job's progress.
   *
   * Sets the progress of this callback tripal job to the given percentage.
   *
   * @param int t
   *   The new percent progress of this callback tripal job.
   *
   * @see \Drupal\tripal\Plugin\TripalJob\TripalJobInterface
   */
  protected function setProgress($progress) {
    $this->_progress = $progress;
  }

  /**
   * Sets this job's user.
   *
   * Sets the user of this callback tripal job that started it.
   *
   * @param int t
   *   The user that started this callback tripal job.
   *
   * @see \Drupal\tripal\Plugin\TripalJob\TripalJobInterface
   */
  protected function setUser($user) {
    $this->_user = $user;
  }

  /*
   * This plugin's job ID.
   */
  private $_jobID;

  /*
   * This plugin's status.
   */
  private $_status;

  /*
   * This plugin's start time.
   */
  private $_startTime;

  /*
   * This plugin's end time.
   */
  private $_endTime;

  /*
   * This plugin's progress.
   */
  private $_progress;

  /*
   * This plugin's user.
   */
  private $_user;

  /*
   * This plugin's ID.
   */
  private $_pluginId;

  /*
   * This plugin's definition.
   */
  private $_pluginDefinition;
}
