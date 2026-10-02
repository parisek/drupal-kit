<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Kernel\PostUpdate;

use Drupal\Core\Language\Language;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the menu locations post-update functions.
 *
 * Kernel tests never auto-import a module's config/install files (that only
 * happens through the real module installer's install path) — this test's
 * setUp() deliberately does NOT call installConfig(['drupal_kit']), which
 * puts the container in exactly the state an existing site is in: the
 * module is enabled, and drupal_kit.menu_locations does not exist. That is
 * the bug the update function fixes.
 *
 * @group drupal_kit
 */
class MenuLocationsPostUpdateKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['drupal_kit', 'system'];

  /**
   * The update installs the config object when it is missing.
   */
  public function testUpdateInstallsMissingConfig(): void {
    $this->assertSame([], $this->config('drupal_kit.menu_locations')->get());

    $this->requirePostUpdateFile();
    drupal_kit_post_update_menu_locations();

    $this->assertSame([], $this->config('drupal_kit.menu_locations')->get('locations'));
    $this->assertNotSame([], $this->config('drupal_kit.menu_locations')->get());
  }

  /**
   * Running the update twice is a no-op — the second run is SKIP-EXISTS.
   *
   * A site that already has an assignment must not have it clobbered by a
   * later `drush updb` run picking up this same update again (e.g. a
   * re-run after an interrupted deploy).
   */
  public function testUpdateIsIdempotentAndDoesNotOverwriteAnExistingAssignment(): void {
    $this->config('drupal_kit.menu_locations')
      ->set('locations', ['arkero' => ['header_menu' => 'main']])
      ->save();

    $this->requirePostUpdateFile();
    drupal_kit_post_update_menu_locations();

    $this->assertSame(
      ['arkero' => ['header_menu' => 'main']],
      $this->config('drupal_kit.menu_locations')->get('locations'),
    );
  }

  /**
   * The langcode update gives the object the site's default language.
   *
   * @param string|null $langcode
   *   The langcode the object has before the update. NULL: no key.
   * @param string $default_langcode
   *   The site's default language.
   * @param string $expected
   *   The langcode after the update.
   *
   * @dataProvider providerLangcode
   */
  public function testLangcodeUpdate(?string $langcode, string $default_langcode, string $expected): void {
    $this->container->get('language.default')->set(new Language(['id' => $default_langcode]));
    $config = $this->config('drupal_kit.menu_locations')->set('locations', []);
    if ($langcode !== NULL) {
      $config->set('langcode', $langcode);
    }
    $config->save();

    $this->requirePostUpdateFile();
    drupal_kit_post_update_menu_locations_langcode();

    $this->assertSame($expected, $this->config('drupal_kit.menu_locations')->get('langcode'));
  }

  /**
   * Data provider for testLangcodeUpdate().
   *
   * @return array<string, array{0: string|null, 1: string, 2: string}>
   *   Cases: langcode before, site default, langcode after.
   */
  public static function providerLangcode(): array {
    return [
      'missing key, Czech site' => [NULL, 'cs', 'cs'],
      'missing key, English site' => [NULL, 'en', 'en'],
      'shipped en, Czech site' => ['en', 'cs', 'cs'],
      'shipped en, English site' => ['en', 'en', 'en'],
      'already Czech' => ['cs', 'cs', 'cs'],
      'German on a Czech site' => ['de', 'cs', 'de'],
    ];
  }

  /**
   * The langcode update does not create the object.
   */
  public function testLangcodeUpdateSkipsMissingConfig(): void {
    $this->requirePostUpdateFile();
    drupal_kit_post_update_menu_locations_langcode();

    $this->assertTrue($this->config('drupal_kit.menu_locations')->isNew());
  }

  /**
   * Loads drupal_kit.post_update.php, the way drush's own updater does.
   *
   * The module list's getPath() returns a path relative to the Drupal root
   * ($this->root), not the current working directory — require_once needs
   * the absolute path built from it.
   */
  protected function requirePostUpdateFile(): void {
    $module_path = $this->container->get('extension.list.module')->getPath('drupal_kit');
    require_once $this->root . '/' . $module_path . '/drupal_kit.post_update.php';
  }

  /**
   * The flag update declares the flag off on a site that predates it.
   */
  public function testFeatureFlagUpdateDeclaresTheFlagOff(): void {
    $this->assertNull($this->config('drupal_kit.feature_flags')->get('menu_locations_log'));

    $this->requirePostUpdateFile();
    drupal_kit_post_update_feature_flag_menu_locations_log();

    $this->assertFalse($this->config('drupal_kit.feature_flags')->get('menu_locations_log'));
  }

  /**
   * The flag update keeps a value a site already chose.
   */
  public function testFeatureFlagUpdateKeepsAnEnabledFlag(): void {
    $this->config('drupal_kit.feature_flags')->set('menu_locations_log', TRUE)->save();

    $this->requirePostUpdateFile();
    drupal_kit_post_update_feature_flag_menu_locations_log();

    $this->assertTrue($this->config('drupal_kit.feature_flags')->get('menu_locations_log'));
  }

}
