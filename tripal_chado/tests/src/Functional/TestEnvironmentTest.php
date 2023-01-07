<?php

namespace Drupal\Tests\tripal_chado\Functional;

/**
 * Tests the Chado Test Environment.
 *
 * @group Tripal
 * @group Tripal Chado
 */
class TestEnvironmentTest extends ChadoTestBrowserBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['tripal', 'field_ui'];

  /**
   * Tests the Chado Test Environment. Specifically,
   *  - All services initiating a chado instance should use the test schema.
   */
  public function testChadoTestEnvironment() {

    $testEnviro_chado = $this->createTestSchema(ChadoTestBrowserBase::INIT_CHADO_EMPTY);
    $testEnviro_chado_schemaname = $testEnviro_chado->getSchemaName();
    $coreEnviro_chado = \Drupal::service('tripal_chado.database');
    $coreEnviro_chado_schemaname = $coreEnviro_chado->getSchemaName();

    $this->assertEquals($testEnviro_chado_schemaname, $coreEnviro_chado_schemaname,
      "Core Services are not using the test schema.");
  }
}
