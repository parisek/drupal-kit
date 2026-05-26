<?php

namespace Drupal\Tests\custom_components\Kernel\Services;

use Drupal\Tests\custom_components\Kernel\EntityHelperKernelTestBase;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\system\Entity\Menu;

/**
 * Behavioral tests for EntityHelper::getMenu against a real menu API.
 *
 * @coversDefaultClass \Drupal\custom_components\Services\EntityHelper
 * @group custom_components
 */
class EntityHelperMenuTest extends EntityHelperKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'custom_components',
    'system',
    'user',
    'menu_link_content',
    'link',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Order matters: menu_link_content has a revision_user field that
    // references the user entity type, so user must be installed first.
    $this->installEntitySchema('user');
    $this->installEntitySchema('menu_link_content');

    // The `menu.language_tree_manipulator` core-patch service is now
    // optional — MenuTreeBuilder gracefully skips its manipulator entry
    // when the service is absent (#43). No stub needed here anymore.
  }

  /**
   * @covers ::getMenu
   */
  public function testEmptyMenuReturnsEmptyArray(): void {
    $this->createMenu('empty');

    $items = $this->entityHelper->getMenu('empty');

    $this->assertSame([], $items);
  }

  /**
   * @covers ::getMenu
   *
   * Loads a menu with one root link and asserts the public shape of the
   * returned items — id and title at minimum, which is the contract
   * MenuTreeBuilder must preserve when #6 extracts it.
   */
  public function testFlatMenuReturnsExpectedLinkShape(): void {
    $this->createMenu('main_nav');
    $this->createMenuLink('main_nav', 'Home', 'internal:/');

    $items = $this->entityHelper->getMenu('main_nav');

    $this->assertNotEmpty($items, 'getMenu returns non-empty array when menu has visible links.');
    $first = reset($items);
    $this->assertArrayHasKey('title', $first);
    $this->assertSame('Home', $first['title']);
  }

  /**
   * @covers ::getMenu
   */
  public function testHiddenMenuLinksAreSkipped(): void {
    $this->createMenu('with_hidden');
    $this->createMenuLink('with_hidden', 'Visible', 'internal:/visible', FALSE);
    $this->createMenuLink('with_hidden', 'Hidden', 'internal:/hidden', TRUE);

    $items = $this->entityHelper->getMenu('with_hidden');

    $titles = array_map(static fn ($i) => $i['title'], $items);
    $this->assertContains('Visible', $titles);
    $this->assertNotContains('Hidden', $titles);
  }

  /**
   * Create a custom menu.
   */
  protected function createMenu(string $id): Menu {
    $menu = Menu::create(['id' => $id, 'label' => ucfirst($id)]);
    $menu->save();
    return $menu;
  }

  /**
   * Create a top-level menu link content entity.
   *
   * Hierarchical (nested) menu links are intentionally not supported here —
   * MenuLinkContent's parent format is "menu_link_content:{uuid}" and adding
   * that wiring belongs in the PR that actually tests nested-menu output.
   */
  protected function createMenuLink(
    string $menu_name,
    string $title,
    string $uri,
    bool $hidden = FALSE,
  ): MenuLinkContent {
    $link = MenuLinkContent::create([
      'menu_name' => $menu_name,
      'title' => $title,
      'link' => ['uri' => $uri],
      'enabled' => $hidden ? 0 : 1,
    ]);
    $link->save();
    return $link;
  }

}
