<?php

namespace Drupal\Tests\drupal_kit\Kernel;

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
 * @group drupal_kit
 */
class SmokeTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['drupal_kit'];

  /**
   * The module is discoverable and enabled in the kernel container.
   */
  public function testModuleIsEnabled(): void {
    $this->assertTrue(
      $this->container->get('module_handler')->moduleExists('drupal_kit'),
      'drupal_kit module is enabled in the kernel container.',
    );
  }

}
