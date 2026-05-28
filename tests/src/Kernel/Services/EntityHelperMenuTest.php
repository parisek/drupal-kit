<?php

namespace Drupal\Tests\custom_components\Kernel\Services;

use Drupal\Tests\custom_components\Kernel\EntityHelperKernelTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
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
    // Needed for the field_formatter / menu_item_extras enrichment test.
    'field',
    // Aligns with the convention of other field-using kernel tests in
    // this repo (EntityHelperFormatFieldKernelTest). The `string` field
    // type is provided by Drupal core, so the test technically runs
    // without `text` — keeping `text` here for consistency and to
    // cover any config-schema validation paths that downstream Drupal
    // versions may route through the text module.
    'text',
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
   * @covers ::getMenu
   *
   * Nested menu links flow through MenuTreeBuilder::renderLinks's
   * recursive branch — parent link's `below` array carries the child
   * with the same shape.
   */
  public function testNestedMenuLinksRecurseIntoBelow(): void {
    $this->createMenu('with_nested');
    $parent = $this->createMenuLink('with_nested', 'Parent', 'internal:/parent');
    $this->createMenuLink(
      'with_nested',
      'Child',
      'internal:/child',
      FALSE,
      'menu_link_content:' . $parent->uuid(),
    );

    $items = $this->entityHelper->getMenu('with_nested');

    $this->assertNotEmpty($items, 'getMenu returns the parent.');
    $top = reset($items);
    $this->assertSame('Parent', $top['title']);
    $this->assertNotEmpty($top['below'], 'Child link is nested under the parent.');
    $child = reset($top['below']);
    $this->assertSame('Child', $child['title']);
    // Recursive shape must mirror the top level — `below` is an array
    // (empty for a leaf) and `is_active` is set.
    $this->assertIsArray($child['below']);
    $this->assertArrayHasKey('is_active', $child);
  }

  /**
   * @covers ::getMenu
   *
   * The menu_item_extras enrichment path — when the consumer passes
   * a field_formatter (EntityHelper::getMenu wires its own formatField()),
   * every `field_*` field on the menu_link_content entity is mapped
   * into the link data under the field name minus the `field_` prefix.
   */
  public function testFieldFormatterEnrichesMenuItemWithExtras(): void {
    // Attach a custom string field to the menu_link_content bundle.
    // Mirrors what the contrib menu_item_extras module does: arbitrary
    // field_* fields on menu_link_content that the consumer wants
    // surfaced alongside the link.
    FieldStorageConfig::create([
      'field_name' => 'field_subtitle',
      'entity_type' => 'menu_link_content',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_subtitle',
      'entity_type' => 'menu_link_content',
      'bundle' => 'menu_link_content',
      'label' => 'Subtitle',
    ])->save();

    $this->createMenu('with_extras');
    $link = MenuLinkContent::create([
      'menu_name' => 'with_extras',
      'title' => 'Home',
      'link' => ['uri' => 'internal:/'],
      'enabled' => 1,
      'field_subtitle' => 'Welcome',
    ]);
    $link->save();

    $items = $this->entityHelper->getMenu('with_extras');

    $this->assertNotEmpty($items);
    $first = reset($items);
    // `field_subtitle` is surfaced under `subtitle` — the field_ prefix
    // is stripped by the enrichment loop in renderLinks().
    $this->assertArrayHasKey('subtitle', $first);
    $this->assertSame('Welcome', $first['subtitle']);
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
   * Create a menu link content entity.
   *
   * @param string|null $parent
   *   When non-NULL, the link is created as a child of the given
   *   `menu_link_content:{uuid}` plugin id (testNestedMenuLinksRecurseIntoBelow
   *   relies on this for the recursive renderLinks branch).
   */
  protected function createMenuLink(
    string $menu_name,
    string $title,
    string $uri,
    bool $hidden = FALSE,
    ?string $parent = NULL,
  ): MenuLinkContent {
    $values = [
      'menu_name' => $menu_name,
      'title' => $title,
      'link' => ['uri' => $uri],
      'enabled' => $hidden ? 0 : 1,
    ];
    if ($parent !== NULL) {
      $values['parent'] = $parent;
    }
    $link = MenuLinkContent::create($values);
    $link->save();
    return $link;
  }

}
