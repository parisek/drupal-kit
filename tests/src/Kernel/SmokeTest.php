<?php

namespace Drupal\Tests\custom_components\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Verifies the kernel-test pipeline can boot the module.
 *
 * Canary for the CI workflow: passes only when composer has scaffolded
 * Drupal at web/core, the module is symlinked into web/modules/contrib,
 * and PHPUnit can bootstrap from web/core/tests/bootstrap.php with sqlite
 * in-memory. Deliberately does NOT instantiate EntityHelper — that has 15
 * dependencies and would couple this smoke test to issue #4 fixtures.
 *
 * @group custom_components
 */
class SmokeTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['custom_components'];

  /**
   * The module is discoverable and enabled in the kernel container.
   */
  public function testModuleIsEnabled(): void {
    $this->assertTrue(
      $this->container->get('module_handler')->moduleExists('custom_components'),
      'custom_components module is enabled in the kernel container.',
    );
  }

}
