<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Kernel\Services;

use Drupal\KernelTests\KernelTestBase;
use Drupal\drupal_kit\Services\FeatureFlags;

/**
 * Pins what the datalayer feature ships (#160).
 *
 * A flag that is off, and settings that make a site that turns it on send
 * exactly one thing, the lead.
 *
 * @group drupal_kit
 */
class DataLayerConfigKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['drupal_kit', 'system'];

  /**
   * The flag ships declared and off.
   */
  public function testTheFlagShipsOff(): void {
    $this->installConfig(['drupal_kit']);

    $flags = $this->config(FeatureFlags::CONFIG_NAME)->get();

    $this->assertArrayHasKey(FeatureFlags::FLAG_DATALAYER, $flags);
    $this->assertFalse($flags[FeatureFlags::FLAG_DATALAYER]);
    $this->assertFalse($this->container->get('drupal_kit.feature_flags')->enabled(FeatureFlags::FLAG_DATALAYER));
  }

  /**
   * Every extra the layer can add ships off; the lead form data ships whole.
   *
   * "all" is the default because every site-local copy this layer replaces
   * sends the whole submission, and a tag manager container may read it.
   */
  public function testSettingsShipWithEveryExtraOff(): void {
    $this->installConfig(['drupal_kit']);

    $settings = $this->config('drupal_kit.datalayer');

    $this->assertFalse($settings->get('status_codes'));
    $this->assertFalse($settings->get('page_context'));
    $this->assertFalse($settings->get('click_events'));
    $this->assertSame('all', $settings->get('lead_form_data'));
    $this->assertSame([], $settings->get('lead_form_keys'));
  }

  /**
   * A mode the layer does not know fails schema validation.
   */
  public function testSchemaRejectsAnUnknownLeadFormDataMode(): void {
    $this->installConfig(['drupal_kit']);

    $data = $this->config('drupal_kit.datalayer')->get();
    $data['lead_form_data'] = 'some';
    $violations = $this->container->get('config.typed')
      ->createFromNameAndData('drupal_kit.datalayer', $data)
      ->validate();

    $this->assertGreaterThan(0, $violations->count());
  }

}
