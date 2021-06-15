<?php

namespace Drupal\tripal\Plugin\TripalJob;

use Drupal\tripal\Plugin\TripalJob\TripalJobBase;

/**
 * Callback Tripal Job Plugin.
 */
interface CallbackTripalJob implements TripalJobBase {

  /**
   * Creates a new callback job.
   *
   * Creates a new callback tripal job with the given callback function, arguments, includes, user,
   * and factory.
   *
   * @param string callback
   *   The Callback function used to run this job. This must be a valid function name with absolute
   *   scoping.
   *
   * @param array arguments
   *   An array of values passed as arguments to the callback function. The given callback function
   *   must be able to accept the given array as arguments.
   *
   * @param array includes
   *   An array of include paths required by the callback function.
   *
   * @param string user
   *   The name of the user that created this job.
   *
   * @param \Drupal\tripal\Plugin\TripalJob\TripalJobFactoryInterface factory
   *   The Callback Tripal Job Factory that created this new instance.
   *
   * @return CallBackTripalJob
   *   The instance of a new callback tripal job.
   */
  public static function create($callback,$arguments,$includes,$user,$factory) {
    $ret = new CallbackTripalJob($factory);
    $database = \Drupal::database();
    $ret->_status = "Waiting";
    $ret->_startTime = 0
    $ret->_endTime = 0
    $ret->_progress = 0
    $ret->_user = $user
    $ret->_callback = $callback
    $ret->_arguments = $arguments
    $ret->_includes = $includes
    $sql = $database->insert("callback_tripal_jobs")->fields(
      [
        "status" => $ret->_status
        ,"start_time" => $ret->_startTime
        ,"end_time" => $ret->_endTime
        ,"progress" => $ret->_progress
        ,"user" => $ret->_user
        ,"callback" => $ret->_callback
        ,"arguments" => serialize($ret->_arguments)
        ,"includes" => serialize($ret->_includes)
      ]
    )
    $ret->_jobID = $sql->execute();
    return $ret;
  }

  /**
   * Loads callback job.
   *
   * Loads an existing callback job with the given job ID.
   *
   * @param int id
   *   The returned callback job's ID.
   *
   * @param \Drupal\tripal\Plugin\TripalJob\TripalJobFactoryInterface factory
   *   The Callback Tripal Job Factory that created this new instance.
   *
   * @return CallBackTripalJob
   *   The instance of an existing callback tripal job with the given job ID.
   */
  public static function loadFromDB($id,$factory) {
    $database = \Drupal::database();
    $query = $database->query(
      "SELECT * FROM `callback_tripal_jobs` WHERE `id` = ':id'"
      ,[":id" => $id]
    );
    $job = $query->fetchObject();
    if (!$job) {
      return NULL;
    }
    return loadFromObject($job,$factory);
  }

  /**
   * Loads callback job.
   *
   * Loads an existing callback job from the given database fetch object.
   *
   * @param object job
   *   The database fetch object containing all data to populate an instance of an existing callback
   *   tripal job.
   *
   * @param \Drupal\tripal\Plugin\TripalJob\TripalJobFactoryInterface factory
   *   The Callback Tripal Job Factory that created this new instance.
   *
   * @return CallBackTripalJob
   *   The instance of an existing callback tripal job from the given fetch object.
   */
  public static function loadFromObject($job,$factory) {
    $ret = new CallbackTripalJob($factory);
    $ret->_jobID = $job->id;
    $ret->_status = $job->waiting;
    $ret->_startTime = $job->start_time;
    $ret->_endTime = $job->end_time;
    $ret->_progress = $job->progress;
    $ret->_user = $job->user;
    $ret->_callback = $job->callback;
    $ret->_arguments = unserialize($job->arguments);
    $ret->_includes = unserialize($job->includes);
    return $ret;
  }

  /**
   * Constructs new Callback Tripal Job.
   *
   * Constructs this new Callback Tripal Job with the given factory.
   *
   * @param \Drupal\tripal\Plugin\TripalJob\TripalJobFactoryInterface factory
   *   The Callback Tripal Job Factory that created this new instance.
   */
  private function __construct($factory) {
    parent::__construct($factory);
  }

  /**
   * @see \Drupal\tripal\Plugin\TripalJob\TripalJobInterface
   */
  public function execute() {
    $this->setStartTime(time());
    $this->setStatus("running");
    $this->updateDB();
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
   * Sets this job's status.
   *
   * Sets the status of this callback tripal job to the given status. This does not update the
   * database.
   *
   * @param string status
   *   The new status of this job. This must be one of the legal string status values.
   *
   * @see \Drupal\tripal\Plugin\TripalJob\TripalJobInterface
   */
  private function setStatus($status) {
    $this->_status = $status;
  }

  /**
   * Sets this job's start time.
   *
   * Sets the start time of this callback tripal job to the given time. This does not update the
   * database.
   *
   * @param int t
   *   The time this callback tripal job started.
   *
   * @see \Drupal\tripal\Plugin\TripalJob\TripalJobInterface
   */
  private function setStartTime($t) {
    $this->_startTime = $t;
  }

  /**
   * Updates to database.
   *
   * Updates all fields of this callback tripal job to the database.
   */
  private function updateDB() {
    $this->status = $status;
    $database = \Drupal::database();
    $u = $database->update("callback_tripal_jobs");
    $u->fields(
      [
        "status" => $this->_status
        ,"start_time" => $this->_startTime
        ,"end_time" => $this->_endTime
        ,"progress" => $this->_progress
        ,"user" => $this->_user
        ,"callback" => $this->_callback
        ,"arguments" => serialize($this->_arguments)
        ,"includes" => serialize($this->_includes)
      ]
    );
    $u->condition("id",$this->_jobID);
    $u->execute();
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
}
