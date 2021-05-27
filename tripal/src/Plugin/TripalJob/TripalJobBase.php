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
    return $this->pluginId;
  }

  /**
   * @see Drupal\Component\Plugin\PluginInspectionInterface
   */
  public function getPluginDefinition() {
    return $this->pluginDefinition;
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
    $this->pluginId = $factory->getPluginId();
    $this->pluginDefinition = $factory->getPluginDefinition();
  }

  /*
   * This plugin's ID.
   */
  private $pluginId;

  /*
   * This plugin's definition.
   */
  private $pluginDefinition;
}
