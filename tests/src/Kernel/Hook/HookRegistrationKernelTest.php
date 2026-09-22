<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Kernel\Hook;

use Drupal\KernelTests\KernelTestBase;

/**
 * Every hook this module used to declare procedurally is still registered.
 *
 * A #[Hook] class fails in a way a procedural function cannot: by not being
 * found. A renamed method, a dropped attribute, a class moved out of
 * src/Hook/ — each leaves code that reads correctly, passes phpcs and
 * PHPStan, and is never called. Nothing else in the suite would notice,
 * because a hook that does not run makes no assertion fail; it only makes a
 * warning message, a sitemap entry or a Vite filename quietly stop
 * happening.
 *
 * The list below is the eight hooks drupal_kit.module declared before the
 * migration, minus hook_module_implements_alter, which core allows only as a
 * procedural function and which Order::Last replaces.
 *
 * @group drupal_kit
 */
class HookRegistrationKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['drupal_kit', 'system'];

  /**
   * The hooks that must survive the move out of drupal_kit.module.
   *
   * @return array<string, array{string}>
   *   Hook names, keyed by themselves so a failure names the hook.
   */
  public static function hookProvider(): array {
    $hooks = [
      'page_attachments_alter',
      'locale_translation_projects_alter',
      'form_alter',
      'file_download',
      'theme',
      'simple_sitemap_links_alter',
      'library_info_alter',
    ];

    return array_combine($hooks, array_map(static fn(string $hook): array => [$hook], $hooks));
  }

  /**
   * The module still implements the hook, wherever the code now lives.
   *
   * @dataProvider hookProvider
   */
  public function testTheHookIsRegistered(string $hook): void {
    $this->assertTrue(
      $this->container->get('module_handler')->hasImplementations($hook, ['drupal_kit']),
      "drupal_kit no longer implements hook_$hook.",
    );
  }

  /**
   * No stray procedural hook survives in the module.
   *
   * The migration is only finished when the .module file is gone. A half
   * migration — the class added, the function left behind — registers the
   * hook twice and runs it twice, which for an alter hook means applying the
   * same change to the same array a second time.
   */
  public function testTheModuleFileIsGone(): void {
    $path = $this->container->get('extension.path.resolver')->getPath('module', 'drupal_kit');

    $this->assertFileDoesNotExist(
      $this->root . '/' . $path . '/drupal_kit.module',
      'Every hook lives in src/Hook/ now.',
    );
  }

}
