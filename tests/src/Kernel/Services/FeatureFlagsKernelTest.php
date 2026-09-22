<?php

namespace Drupal\Tests\drupal_kit\Kernel\Services;

use Drupal\KernelTests\KernelTestBase;
use Drupal\drupal_kit\Services\FeatureFlags;

/**
 * Kernel tests for the feature-flag reader (#115).
 *
 * The cases that matter are the ones returning FALSE. A flag reading TRUE by
 * accident is a behaviour change on upgrade, which is the one thing
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
   * The reader under test, built on the shipped allowlist.
   *
   * Constructed rather than fetched from the container in most cases: the
   * stub subclass is what carries a declared flag, and the container only
   * knows the shipped class.
   *
   * @param class-string<FeatureFlags> $class
   *   The reader to build — the shipped class, or the stub that declares a
   *   flag.
   */
  private function flags(string $class = FeatureFlags::class): FeatureFlags {
    return new $class(\Drupal::service('config.factory'));
  }

  /**
   * Write a flag straight into config storage.
   *
   * Through the storage rather than the config factory on purpose. The
   * schema is FullyValidatable, so a kernel test's strict schema checking
   * rejects an undeclared key written through the API — that is the typo
   * guard doing its job. Storage is also how such a value really arrives on
   * a site: a hand-edited file, or an older export.
   */
  private function storeFlag(string $name, $value): void {
    \Drupal::service('config.storage')
      ->write(FeatureFlags::CONFIG_NAME, [$name => $value]);
    \Drupal::configFactory()->reset(FeatureFlags::CONFIG_NAME);
  }

  /**
   * The module ships no flags, so nothing it declares can be on.
   *
   * @covers ::enabled
   */
  public function testNoFlagShipsEnabled(): void {
    $this->storeFlag(FeatureFlagsTestStub::EXAMPLE_FEATURE, TRUE);

    $this->assertFalse($this->flags()->enabled(FeatureFlagsTestStub::EXAMPLE_FEATURE));
  }

  /**
   * A declared flag the project set reads TRUE.
   *
   * @covers ::enabled
   */
  public function testDeclaredFlagTheProjectSetReadsTrue(): void {
    $this->storeFlag(FeatureFlagsTestStub::EXAMPLE_FEATURE, TRUE);

    $this->assertTrue($this->flags(FeatureFlagsTestStub::class)->enabled(FeatureFlagsTestStub::EXAMPLE_FEATURE));
  }

  /**
   * A declared flag set to FALSE stays off.
   *
   * @covers ::enabled
   */
  public function testDeclaredFlagSetToFalseReadsFalse(): void {
    $this->storeFlag(FeatureFlagsTestStub::EXAMPLE_FEATURE, FALSE);

    $this->assertFalse($this->flags(FeatureFlagsTestStub::class)->enabled(FeatureFlagsTestStub::EXAMPLE_FEATURE));
  }

  /**
   * A flag nobody declared is off even when config turns it on.
   *
   * The allowlist, not the schema, enforces this at runtime: schema
   * validation runs in tests and in Config Inspector, never on a production
   * read.
   *
   * @covers ::enabled
   */
  public function testUndeclaredFlagIsOffEvenWhenSetInConfig(): void {
    $this->storeFlag('not_a_flag', TRUE);

    $this->assertTrue(
      \Drupal::config(FeatureFlags::CONFIG_NAME)->get('not_a_flag'),
      'the value really is in config',
    );
    $this->assertFalse($this->flags(FeatureFlagsTestStub::class)->enabled('not_a_flag'));
  }

  /**
   * A declared flag absent from config is off.
   *
   * The upgrade case: the site's export predates the flag, so the key is
   * simply missing and behaviour must be what it was before.
   *
   * @covers ::enabled
   */
  public function testDeclaredFlagAbsentFromConfigIsOff(): void {
    $this->storeFlag('something_else', TRUE);

    $this->assertFalse($this->flags(FeatureFlagsTestStub::class)->enabled(FeatureFlagsTestStub::EXAMPLE_FEATURE));
  }

  /**
   * A missing config object is off, not an error.
   *
   * @covers ::enabled
   */
  public function testMissingConfigObjectIsOff(): void {
    $this->assertTrue(\Drupal::config(FeatureFlags::CONFIG_NAME)->isNew());
    $this->assertFalse($this->flags(FeatureFlagsTestStub::class)->enabled(FeatureFlagsTestStub::EXAMPLE_FEATURE));
  }

  /**
   * A non-boolean value does not turn a flag on.
   *
   * Written through storage, so no schema cast has normalised it — which is
   * exactly when the reader's own strictness is the only thing left.
   *
   * @covers ::enabled
   */
  public function testNonBooleanValuesDoNotEnable(): void {
    foreach ([1, '1', 'true', 'yes', [], 0, '', NULL] as $value) {
      $this->storeFlag(FeatureFlagsTestStub::EXAMPLE_FEATURE, $value);
      $this->assertFalse(
        $this->flags(FeatureFlagsTestStub::class)->enabled(FeatureFlagsTestStub::EXAMPLE_FEATURE),
        var_export($value, TRUE) . ' must not enable a flag',
      );
    }
  }

  /**
   * An empty flag name asks nothing and gets nothing.
   *
   * @covers ::enabled
   */
  public function testEmptyNameIsOff(): void {
    $this->assertFalse($this->flags(FeatureFlagsTestStub::class)->enabled(''));
  }

  /**
   * The module ships no config object yet, and that is deliberate.
   *
   * Drupal skips a config/install file with no keys — FileStorage::decode()
   * returns FALSE for empty data — so an empty object cannot ship at all.
   * The first flag brings the object with it. This test is the tripwire: the
   * moment an install file appears, it must declare flags and they must all
   * be FALSE.
   *
   * @covers ::enabled
   */
  public function testTheModuleShipsNoFlagsOn(): void {
    $this->installConfig(['drupal_kit']);

    $shipped = array_filter(
      \Drupal::config(FeatureFlags::CONFIG_NAME)->getRawData(),
      static fn(string $key): bool => !str_starts_with($key, '_core'),
      ARRAY_FILTER_USE_KEY,
    );

    $this->assertSame([], array_filter($shipped), 'no shipped default turns a flag on');
  }

  /**
   * The container really hands out the reader.
   *
   * Constructing the class proves the logic; it does not prove a hook can
   * get hold of it. A missing or misspelled service id leaves every call
   * site fatal on a real site and every test here green, because they all
   * build their own instance.
   *
   * @covers ::enabled
   */
  public function testTheServiceIsRegistered(): void {
    $flags = \Drupal::service('drupal_kit.feature_flags');

    $this->assertInstanceOf(FeatureFlags::class, $flags);
    $this->assertFalse($flags->enabled(FeatureFlagsTestStub::EXAMPLE_FEATURE));
  }

}
