<?php

namespace Drupal\tripal\Plugin\TripalJob;

use Drupal\tripal\Plugin\TripalJob\TripalJobInterface;
use Drupal\Component\Plugin\PluginBase;

/**
 * Base class for Tripal Job plugins.
 */
interface TripalJobBase implements TripalJobInterface {

  /*
   * This plugin's ID.
   */
  private $pluginId;

  /*
   * This plugin's definition.
   */
  private $pluginDefinition;

  /**
   * @see TripalJobInterface
   */
  public function __construct($factory) {
    $this->pluginId = $factory->getPluginId();
    $this->pluginDefinition = $factory->getPluginDefinition();
  }

  /**
   * @see TripalJobInterface
   */
  public function status();

  /**
   * @see TripalJobInterface
   */
  public function startTime();

  /**
   * @see TripalJobInterface
   */
  public function endTime();

  /**
   * @see TripalJobInterface
   */
  public function progress();

  /**
   * @see TripalJobInterface
   */
  public function user();

  /**
   * @see Drupal\Component\Plugin\PluginInspectionInterface
   */
  public function getPluginId() {
    return $this->pluginId;
  }

  /**
   * @see Drupal\Component\Plugin\PluginInspectionInterface
   */
  public function getPluginDefinition() {
    return $this->pluginDefinition;
  }
}
