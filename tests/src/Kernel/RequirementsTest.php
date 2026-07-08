<?php

namespace Drupal\Tests\drupal_kit\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;

/**
 * Tests the runtime requirements reported on the status page.
 *
 * The kernel container never carries menu.language_tree_manipulator
 * (it ships via a core patch), so the tests cover the missing-service
 * side: no entry on a monolingual site, a warning on a multilingual
 * one.
 *
 * @group drupal_kit
 */
class RequirementsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'drupal_kit',
    'system',
    'user',
    'language',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    include_once $this->root . '/core/includes/install.inc';
    include_once __DIR__ . '/../../../drupal_kit.install';
  }

  /**
   * Non-runtime phases report nothing.
   */
  public function testInstallPhaseReportsNothing(): void {
    $this->assertSame([], drupal_kit_requirements('install'));
  }

  /**
   * A monolingual site gets no requirement entry.
   */
  public function testMonolingualSiteReportsNothing(): void {
    $this->assertArrayNotHasKey(
      'drupal_kit_language_tree_manipulator',
      drupal_kit_requirements('runtime'),
    );
  }

  /**
   * A multilingual site without the service gets a warning.
   */
  public function testMultilingualSiteWithoutServiceWarns(): void {
    ConfigurableLanguage::createFromLangcode('cs')->save();

    $requirements = drupal_kit_requirements('runtime');

    $this->assertArrayHasKey('drupal_kit_language_tree_manipulator', $requirements);
    $requirement = $requirements['drupal_kit_language_tree_manipulator'];
    $this->assertSame(REQUIREMENT_WARNING, $requirement['severity']);
    $this->assertStringContainsString(
      'menu.language_tree_manipulator',
      (string) $requirement['description'],
    );
  }

}
