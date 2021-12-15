<?php

namespace Drupal\tripal4\Plugin;

use Drupal\tripal4\Plugin\CollectionPluginManager;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Provides the tripal id space plugin manager.
 */
class IdSpaceManager extends CollectionPluginManager {

  /**
   * Constructs a new tripal id space plugin manager.
   *
   * @param \Traversable $namespaces
   *   An object that implements \Traversable which contains the root paths
   *   keyed by the corresponding namespace to look for plugin implementations.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   Cache backend instance to use.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler to invoke the alter hook with.
   */
  public function __construct(
      \Traversable $namespaces
      ,CacheBackendInterface $cache_backend
      ,ModuleHandlerInterface $module_handler
  ) {
    parent::__construct(
        "Plugin/TripalIdSpace"
        ,$namespaces
        ,$module_handler
        ,'Drupal\tripal4\Plugin\IdSpaceInterface'
        ,'Drupal\tripal4\Annotation\IdSpace'
        ,"tripal_idspace_collection"
    );
    $this->alterInfo("tripal_id_space_info");
    $this->setCacheBackend($cache_backend,"tripal_id_space_plugins");
  }

}