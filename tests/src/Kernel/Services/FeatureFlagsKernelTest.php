<?php

namespace Drupal\Tests\drupal_kit\Kernel\Services;

use Drupal\Core\Config\Schema\SchemaIncompleteException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\drupal_kit\Services\FeatureFlags;

/**
 * Kernel tests for the feature-flag reader (#115).
 *
 * The cases that matter are the ones returning FALSE. A flag that reads TRUE
 * by accident is a behaviour change on upgrade, which is the single thing
 * AGENTS.md § Feature flags forbids.
 *
 * @coversDefaultClass \Drupal\drupal_kit\Services\FeatureFlags
 * @group drupal_kit
 */
class FeatureFlagsKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['drupal_kit', 'system'];

  /**
   * Write a features map into the settings object.
   *
   * @param mixed $features
   *   Whatever the project's config holds — not always a map.
   */
  private function setFeatures($features): void {
    \Drupal::configFactory()
      ->getEditable(FeatureFlags::CONFIG_NAME)
      ->set('features', $features)
      ->save();
  }

  /**
   * The shipped default turns nothing on.
   *
   * @covers ::enabled
   */
  public function testInstalledDefaultsAreOff(): void {
    $this->installConfig(['drupal_kit']);

    $this->assertSame([], \Drupal::config(FeatureFlags::CONFIG_NAME)->get('features'));
    $this->assertFalse(FeatureFlags::enabled('anything'));
  }

  /**
   * A project that set the flag gets the behaviour.
   *
   * @covers ::enabled
   */
  public function testFlagTheProjectSetReadsTrue(): void {
    $this->setFeatures(['example_feature' => TRUE]);

    $this->assertTrue(FeatureFlags::enabled('example_feature'));
  }

  /**
   * A flag the project set to FALSE stays off.
   *
   * @covers ::enabled
   */
  public function testFlagSetToFalseReadsFalse(): void {
    $this->setFeatures(['example_feature' => FALSE]);

    $this->assertFalse(FeatureFlags::enabled('example_feature'));
  }

  /**
   * A flag nobody has heard of is off.
   *
   * This is the upgrade case: the site's config predates the flag, so the
   * key is simply absent and the behaviour must be what it was before.
   *
   * @covers ::enabled
   */
  public function testUnknownFlagIsOff(): void {
    $this->setFeatures(['other_feature' => TRUE]);

    $this->assertFalse(FeatureFlags::enabled('example_feature'));
  }

  /**
   * A missing config object is off, not an error.
   *
   * A site installed before this object existed has no `drupal_kit.settings`
   * until something writes one. Reading it must be silent.
   *
   * @covers ::enabled
   */
  public function testMissingConfigObjectIsOff(): void {
    $this->assertNull(\Drupal::config(FeatureFlags::CONFIG_NAME)->get('features'));
    $this->assertFalse(FeatureFlags::enabled('example_feature'));
  }

  /**
   * The schema casts what a project writes, and casting is the contract.
   *
   * `features` is typed `boolean`, so the config system normalises `1` to
   * TRUE and `0` to FALSE on save. That is the behaviour to rely on — not
   * this reader's own strictness, which only ever sees an already-cast value
   * when the write went through the config API.
   *
   * @covers ::enabled
   */
  public function testTheSchemaCastsOnSave(): void {
    $this->setFeatures(['example_feature' => 1]);
    $this->assertTrue(FeatureFlags::enabled('example_feature'));

    $this->setFeatures(['example_feature' => 0]);
    $this->assertFalse(FeatureFlags::enabled('example_feature'));
  }

  /**
   * The schema refuses a features value that is not a map.
   *
   * The reader still guards against one — a value written straight into
   * storage never passed this check — but the schema is where the shape is
   * enforced, and a test claiming otherwise would describe the wrong
   * mechanism.
   *
   * @covers ::enabled
   */
  public function testTheSchemaRefusesNonMapFeaturesValue(): void {
    $this->expectException(SchemaIncompleteException::class);
    $this->setFeatures('nonsense');
  }

  /**
   * A malformed value that reached storage anyway is off, not a crash.
   *
   * Written through the raw config storage, which is how config arrives on a
   * site hand-edited or restored from an older export.
   *
   * @covers ::enabled
   */
  public function testMalformedStoredValueIsOff(): void {
    \Drupal::service('config.storage')
      ->write(FeatureFlags::CONFIG_NAME, ['features' => 'nonsense']);
    \Drupal::configFactory()->reset(FeatureFlags::CONFIG_NAME);

    $this->assertFalse(FeatureFlags::enabled('example_feature'));
  }

  /**
   * An empty flag name asks nothing and gets nothing.
   *
   * @covers ::enabled
   */
  public function testEmptyNameIsOff(): void {
    $this->setFeatures(['' => TRUE]);

    $this->assertFalse(FeatureFlags::enabled(''));
  }

}
