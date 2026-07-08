<?php

namespace Drupal\Tests\drupal_kit\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\drupal_kit\Services\EntityHelper;

/**
 * Shared base for EntityHelper kernel tests.
 *
 * Boots the module's real service container against an in-memory sqlite
 * Drupal stack. Subclasses install whatever entity schemas / fixtures
 * the methods under test need; this base only does what every
 * EntityHelper test needs: enable the module and expose the service.
 *
 * The mocked unit tests in tests/src/Unit/Services/EntityHelperTest.php
 * remain — they're a faster feedback loop. These kernel tests are the
 * behavioral source of truth that #6's MediaArrayBuilder /
 * MenuTreeBuilder / TaxonomyTreeBuilder extraction is checked against.
 *
 * @group drupal_kit
 */
abstract class EntityHelperKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['drupal_kit'];

  /**
   * The EntityHelper service under test.
   */
  protected EntityHelper $entityHelper;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->entityHelper = $this->container->get('drupal_kit.entity_helper');
  }

}
