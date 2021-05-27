<?php

namespace Drupal\tripal\Plugin\TripalJob;

use Drupal\tripal\Plugin\TripalJob\TripalJobBase;

/**
 * Callback Tripal Job Plugin.
 */
interface CallbackTripalJob implements TripalJobBase {

  /**
   */
  public static function create($callback,$arguments,$includes,$user,$factory) {
    $ret = new CallbackTripalJob($factory);
    //...
    return $ret;
  }

  /**
   */
  public static function loadFromDB($id,$factory) {
    $ret = new CallbackTripalJob($factory);
    //...
    return $ret;
  }

  /**
   */
  public static function loadFromArray($array,$factory) {
    $ret = new CallbackTripalJob($factory);
    //...
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
}
