<?php

namespace Drupal\tripal\Plugin\TripalJob;

use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Provides the TripalJobFactory plugin manager.
 */
class TripalJobFactoryManager extends DefaultPluginManager {


  /**
   * Constructs a new TripalJobFactoryManager object.
   *
   * @param \Traversable $namespaces
   *   An object that implements \Traversable which contains the root paths
   *   keyed by the corresponding namespace to look for plugin implementations.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   Cache backend instance to use.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler to invoke the alter hook with.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct(
      'Plugin/TripalJob',
      $namespaces,
      $module_handler,
      'Drupal\tripal\Plugin\TripalJob\TripalJobFactoryInterface',
      'Drupal\tripal\Annotation\TripalJobFactory'
    );

    $this->alterInfo('tripal_tripal_job_info');
    $this->setCacheBackend($cache_backend, 'tripal_tripal_job_plugins');
  }

}
