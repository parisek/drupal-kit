<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Kernel\DataLayer;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\KernelTests\KernelTestBase;
use Drupal\drupal_kit\Services\FeatureFlags;

/**
 * The layer stays silent while a site-local custom_datalayer is installed.
 *
 * The other tests replace the module handler with a mock. This one installs a
 * stand-in module of that name, so the check runs against the real handler
 * and the real hook registration, as it does on a site in the middle of its
 * migration.
 *
 * @group drupal_kit
 */
class DataLayerGuardKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['drupal_kit', 'system', 'custom_datalayer'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['drupal_kit']);
    $this->config(FeatureFlags::CONFIG_NAME)->set(FeatureFlags::FLAG_DATALAYER, TRUE)->save();
    $this->config('drupal_kit.datalayer')
      ->set('status_codes', TRUE)
      ->set('click_events', TRUE)
      ->save();
  }

  /**
   * The page gets no push and no library, though everything is switched on.
   */
  public function testPageGetsNothing(): void {
    $attachments = [];

    $this->container->get('module_handler')->alter('page_attachments', $attachments);

    $this->assertSame([], $attachments['#attached']['html_head'] ?? []);
    $this->assertSame([], $attachments['#attached']['library'] ?? []);
  }

  /**
   * The status report says the layer is paused.
   */
  public function testStatusReportSaysPaused(): void {
    $requirements = [];
    $this->container->get('module_handler')->invokeAllWith(
      'runtime_requirements',
      static function (callable $hook, string $module) use (&$requirements): void {
        if ($module === 'drupal_kit') {
          $requirements = array_merge($requirements, $hook());
        }
      },
    );

    $this->assertSame(RequirementSeverity::Warning, $requirements['drupal_kit_datalayer']['severity']);
  }

}
