<?php

namespace Drupal\tripal\Plugin\TripalExecutor;

use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Provides the TripalJobFactory plugin manager.
 */
class TripalExecutorManager extends DefaultPluginManager {


  /**
   * Constructs a new TripalExecutorManager object.
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
      'Plugin/TripalExecutor',
      $namespaces,
      $module_handler,
      'Drupal\tripal\Plugin\TripalJob\TripalExecutorInterface',
      'Drupal\tripal\Annotation\TripalExecutor'
    );

    $this->alterInfo('tripal_tripal_executor_info');
    $this->setCacheBackend($cache_backend, 'tripal_tripal_executor_plugins');
  }

}
