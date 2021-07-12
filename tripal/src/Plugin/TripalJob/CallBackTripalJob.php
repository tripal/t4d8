<?php

namespace Drupal\tripal\Plugin\TripalJob;

use Drupal\tripal\Plugin\TripalJob\TripalJobBase;

/**
 * Callback Tripal Job Plugin.
 */
interface CallbackTripalJob implements TripalJobBase {

  /**
   * Get the current tripal job.
   *
   * Get the instane of the current callback tripal job that called the callback function currently
   * running.
   *
   * @return \Drupal\tripal\Plugin\TripalJob\CallbackTripalJob
   *   The current callback tripal job.
   */
  public static function instance($progress) {
    return self::$_instance;
  }

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
    $ret->setStatus("Waiting");
    $ret->setStartTime(0);
    $ret->setEndTime(0);
    $ret->setProgress(0);
    $ret->setUser($user);
    $ret->_callback = $callback
    $ret->_arguments = $arguments
    $ret->_includes = $includes
    $ret->_errorMessage = ""
    $sql = $database->insert("callback_tripal_jobs")->fields(
      [
        "status" => $this->getStatus()
        ,"start_time" => $this->getStartTime()
        ,"end_time" => $this->getEndTime()
        ,"progress" => $this->getProgress()
        ,"user" => $this->getUser()
        ,"callback" => $ret->_callback
        ,"arguments" => serialize($ret->_arguments)
        ,"includes" => serialize($ret->_includes)
        ,"error_msg" => $this->_errorMessage
      ]
    )
    $ret->setJobID($sql->execute());
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
    return self::loadFromObject($job,$factory);
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
    $ret->setJobID($job->id);
    $ret->setStatus($job->status);
    $ret->setStartTime($job->start_time);
    $ret->setEndTime($job->end_time);
    $ret->setProgress($job->progress);
    $ret->setUser($job->user);
    $ret->_callback = $job->callback;
    $ret->_arguments = unserialize($job->arguments);
    $ret->_includes = unserialize($job->includes);
    $ret->_errorMessage = $job->error_msg;
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
    $this->setStatus("Running");
    $this->updateDB();
    try {
      if (is_array($this->job->includes)) {
        foreach ($this->_includes as $path) {
          if ($path) {
            require_once $path;
          }
        }
      }
      self::$_instance = $this;
      call_user_func_array($this->_callback,$this->_arguments);
      self::$_instance = Null;
      $this->setEndTime(time());
      $this->setStatus("Completed");
      $this->setProgress(100);
      $this->updateDB();
    } catch (\Exception $e) {
      $this->setEndTime(time());
      $this->setStatus("Error");
      $this->_errorMessage = $e->getMessage();
      $this->updateDB();
    }
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
        "status" => $this->getStatus()
        ,"start_time" => $this->getStartTime()
        ,"end_time" => $this->getEndTime()
        ,"progress" => $this->getProgress()
        ,"user" => $this->getUser()
        ,"callback" => $this->_callback
        ,"arguments" => serialize($this->_arguments)
        ,"includes" => serialize($this->_includes)
        ,"error_msg" => $this->_errorMessage
      ]
    );
    $u->condition("id",$this->_jobID);
    $u->execute();
  }

  /*
   * This plugin's callback function name.
   */
  private $_callback;

  /*
   * This plugin's arguments array.
   */
  private $_arguments;

  /*
   * This plugin's include path array.
   */
  private $_includes;

  /*
   * This plugin's error message string.
   */
  private $_errorMessage;
  /*
   * The currently active plugin instance.
   */
  private static $_instance = Null;
}
