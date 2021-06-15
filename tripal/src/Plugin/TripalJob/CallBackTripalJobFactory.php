<?php

namespace Drupal\tripal\Plugin\TripalJob;

use Drupal\tripal\Plugin\TripalJob\TripalJobFactoryBase;
use Drupal\tripal\Plugin\TripalJob\CallBackTripalJob;

/**
 * Call Back Tripal Job Factory Plugin.
 *
 * @TripalJob(
 *   id = "callback",
 *   label = @Translation("Call Back Tripal Job"),
 *   description = @Translation("This tripal job uses a callback function, along with arguments and includes, to run its job."),
 */
interface CallBackTripalJobFactory extends TripalJobFactoryBase {

  /**
   * @see \Drupal\tripal\Plugin\TripalJob\TripalJobFactoryInterface
   *
   * @param array $definition
   *   The definition array has the following keys:
   *   - callback: The name of a function to be called when the job is executed.
   *   - arguments:  An array of arguments to be passed on to the callback.
   *   - includes: An array of paths to files that should be included in order
   *     to execute the job. Use the module_load_include function to get a path
   *     for a given file.
   *   - user: The name of the user that created this job.
   */
  public function create($definition) {
    return CallBackTripalJob::create(
      $definition["callback"]
      ,$definition["arguments"]
      ,$definition["includes"]
      ,$definition["user"]
      ,$this
    );
  }

  /**
   * @see \Drupal\tripal\Plugin\TripalJob\TripalJobFactoryInterface
   */
  public function load($id) {
    return CallBackTripalJob::loadFromDB($id,$this);
  }

  /**
   * @see \Drupal\tripal\Plugin\TripalJob\TripalJobFactoryInterface
   */
  public function getList($status) {
    $ret = array()
    $database = \Drupal::database();
    $query = $database->query(
      "SELECT * FROM `callback_tripal_jobs` WHERE status = ':status'"
      ,[":status" => $status]
    );
    while ($job = $query->fetchObject()) {
      array_push($ret,CallBackTripalJob::loadFromObject($job,$this))
    }
    return $ret
  }
}
