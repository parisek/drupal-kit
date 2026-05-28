<?php

namespace Drupal\Tests\custom_components\Kernel;

use Drupal\custom_components\ComponentBase;

/**
 * Minimal concrete ComponentBase subclass for kernel tests.
 *
 * ComponentBase is abstract because BlockBase::build() has no default
 * implementation. Tests that need to exercise the factory + lifecycle
 * (create / construct / buildConfigurationForm / submitConfigurationForm)
 * instantiate this stub. The build() implementation is intentionally
 * a no-op render array — tests on the build output belong in
 * concrete-component test suites, not in the base-class test.
 */
class ComponentBaseStub extends ComponentBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    return [];
  }

}
