<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Kernel\Services;

use Drupal\Core\Access\AccessResultForbidden;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\KernelTestBase;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\system\Entity\Menu;

/**
 * The language filter actually removes links, not just runs.
 *
 * The unit tests prove the wiring: which callable lands in the
 * manipulator list for which injected service. They cannot prove the
 * consequence, because they mock MenuLinkTreeInterface — and the
 * consequence is the whole point. A link the manipulator forbids must
 * not reach the rendered menu.
 *
 * That distinction is not academic. The patch revisions differ in it:
 * the older one REMOVED untranslated links from the tree, while #255
 * marks them with AccessResultForbidden and swaps in an
 * InaccessibleMenuLink so their cacheability still bubbles. Only
 * MenuLinkTree::buildItems() then drops them ("Only render accessible
 * links"). If that were not so, the kit would render empty menu items
 * instead of filtering, and every unit test would still pass.
 *
 * Neither manipulator service is in stock Drupal core — both ship with
 * the patch from https://www.drupal.org/project/drupal/issues/2466553 —
 * so this test registers a stub with revision #255's semantics.
 *
 * @coversDefaultClass \Drupal\drupal_kit\Services\MenuTreeBuilder
 * @group drupal_kit
 */
class MenuTreeBuilderLanguageFilterKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'drupal_kit',
    'system',
    'user',
    'menu_link_content',
    'link',
    'field',
    'text',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // Registered before the container is built, under the name patch
    // revision #255 uses, so the optional argument resolves to it.
    $container->register('menu.language_menu_link_tree_manipulator', StubContextualLanguageManipulator::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Order matters: menu_link_content has a revision_user field, so the
    // user schema must land first.
    $this->installEntitySchema('user');
    $this->installEntitySchema('menu_link_content');
  }

  /**
   * A forbidden link is gone from the result, and its context is kept.
   *
   * @covers ::build
   * @covers ::collectCacheMetadata
   */
  public function testForbiddenLinksAreDroppedAndCacheContextIsCollected(): void {
    Menu::create(['id' => 'filtered', 'label' => 'Filtered'])->save();
    $this->createLink('filtered', 'Keep me', 'route:<front>');
    $this->createLink('filtered', StubContextualLanguageManipulator::FORBIDDEN_TITLE, 'route:<front>');

    $builder = $this->container->get('drupal_kit.menu_tree_builder');
    $items = $builder->build('filtered');

    $titles = array_column($items, 'title');
    $this->assertContains('Keep me', $titles);
    $this->assertNotContains(
      StubContextualLanguageManipulator::FORBIDDEN_TITLE,
      $titles,
      'A link the manipulator forbids must not reach the rendered menu.',
    );

    // Calling process() as a plain callable bypasses transform()'s
    // contextual loop, which is what would otherwise attach this.
    $this->assertContains(
      'languages:language_content',
      $builder->collectCacheMetadata()->getCacheContexts(),
    );
  }

  /**
   * Create a menu_link_content entity.
   */
  private function createLink(string $menu_name, string $title, string $uri): void {
    MenuLinkContent::create([
      'menu_name' => $menu_name,
      'title' => $title,
      'link' => ['uri' => $uri],
      'enabled' => 1,
    ])->save();
  }

}

/**
 * Stands in for the patch's LanguageMenuLinkTreeManipulator.
 *
 * Mirrors revision #255: forbid rather than remove, and declare the
 * content-language cache context. applies() is not implemented, because
 * MenuTreeBuilder calls process() directly — which is the behaviour
 * under test.
 */
class StubContextualLanguageManipulator implements CacheableDependencyInterface {

  /**
   * The title this stub treats as untranslated.
   */
  public const FORBIDDEN_TITLE = 'Drop me';

  /**
   * Forbid the link, as the real manipulator does for untranslated ones.
   *
   * @param array<string, \Drupal\Core\Menu\MenuLinkTreeElement> $tree
   *   The menu link tree.
   * @param mixed $context
   *   Ignored, as in core: process() only passes it to its own recursion.
   *
   * @return array<string, \Drupal\Core\Menu\MenuLinkTreeElement>
   *   The tree, with the matching element marked inaccessible.
   */
  public function process(array $tree, mixed $context): array {
    foreach ($tree as $key => $element) {
      if ($element->link->getTitle() === self::FORBIDDEN_TITLE) {
        $tree[$key]->access = new AccessResultForbidden();
        $tree[$key]->subtree = [];
      }
    }
    return $tree;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return ['languages:language_content'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return -1;
  }

}
