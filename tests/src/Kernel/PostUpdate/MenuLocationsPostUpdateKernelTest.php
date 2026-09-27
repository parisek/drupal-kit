<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Kernel\PostUpdate;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests drupal_kit_post_update_menu_locations().
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
      ->set('locations', ['arkero' => ['header_menu' => ['en' => 'main']]])
      ->save();

    $this->requirePostUpdateFile();
    drupal_kit_post_update_menu_locations();

    $this->assertSame(
      ['arkero' => ['header_menu' => ['en' => 'main']]],
      $this->config('drupal_kit.menu_locations')->get('locations'),
    );
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

}
