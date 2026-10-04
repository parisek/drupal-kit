<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Kernel\PostUpdate;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the update that brings the datalayer feature to an existing site.
 *
 * Like MenuLocationsPostUpdateKernelTest, setUp() does not install the
 * module's config: an existing site has the module enabled and none of the
 * objects this feature adds.
 *
 * @group drupal_kit
 */
class DataLayerPostUpdateKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['drupal_kit', 'system'];

  /**
   * The update declares the flag off and installs the settings.
   */
  public function testUpdateDeclaresTheFlagOffAndInstallsTheSettings(): void {
    $this->assertNull($this->config('drupal_kit.feature_flags')->get('datalayer'));
    $this->assertTrue($this->config('drupal_kit.datalayer')->isNew());

    $this->requirePostUpdateFile();
    drupal_kit_post_update_feature_flag_datalayer();

    $this->assertFalse($this->config('drupal_kit.feature_flags')->get('datalayer'));
    $this->assertFalse($this->config('drupal_kit.datalayer')->isNew());
    $this->assertSame('all', $this->config('drupal_kit.datalayer')->get('lead_form_data'));
    $this->assertFalse($this->config('drupal_kit.datalayer')->get('click_events'));
  }

  /**
   * The update keeps what a site already chose.
   */
  public function testUpdateKeepsTheValuesTheSiteChose(): void {
    $this->config('drupal_kit.feature_flags')->set('datalayer', TRUE)->save();
    $this->config('drupal_kit.datalayer')
      ->set('click_events', TRUE)
      ->set('lead_form_data', 'none')
      ->set('lead_form_keys', [])
      ->set('status_codes', FALSE)
      ->set('page_context', FALSE)
      ->save();

    $this->requirePostUpdateFile();
    drupal_kit_post_update_feature_flag_datalayer();

    $this->assertTrue($this->config('drupal_kit.feature_flags')->get('datalayer'));
    $this->assertTrue($this->config('drupal_kit.datalayer')->get('click_events'));
    $this->assertSame('none', $this->config('drupal_kit.datalayer')->get('lead_form_data'));
  }

  /**
   * Loads drupal_kit.post_update.php, the way drush's own updater does.
   */
  protected function requirePostUpdateFile(): void {
    $module_path = $this->container->get('extension.list.module')->getPath('drupal_kit');
    require_once $this->root . '/' . $module_path . '/drupal_kit.post_update.php';
  }

}
