<?php

namespace Drupal\tripal\Annotation;

use Drupal\Component\Annotation\Plugin;

/**
 * Defines a TripalJobFactory item annotation object.
 *
 * @see \Drupal\tripal\Plugin\TripalJobFactoryManager
 * @see plugin_api
 *
 * @Annotation
 */
class TripalJobFactory extends Plugin {

  /**
   * The plugin ID.
   *
   * @var string
   */
  public $id;

  /**
   * The label of the plugin.
   *
   * @var \Drupal\Core\Annotation\Translation
   *
   * @ingroup plugin_translatable
   */
  public $label;

  /**
   * The description of the plugin.
   *
   * @var \Drupal\Core\Annotation\Translation
   *
   * @ingroup plugin_translatable
   */
  public $description;

}
