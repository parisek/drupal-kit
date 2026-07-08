<?php

namespace Drupal\Tests\drupal_kit\Kernel\Services;

use Drupal\Tests\drupal_kit\Kernel\EntityHelperFieldsKernelTestBase;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\system\Entity\Menu;

/**
 * Behavioural coverage for EntityHelper::getMenuField.
 *
 * Iterates an entity_reference field whose target_type is `menu` and,
 * for each referenced Menu config entity, delegates to ::getMenu — so
 * this also exercises the menu-tree builder pipeline end-to-end on the
 * happy path.
 *
 * @coversDefaultClass \Drupal\drupal_kit\Services\EntityHelper
 * @group drupal_kit
 */
class EntityHelperMenuFieldKernelTest extends EntityHelperFieldsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'drupal_kit',
    'system',
    'user',
    'field',
    'node',
    'text',
    'filter',
    'menu_link_content',
    'link',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('menu_link_content');
  }

  /**
   * @covers ::getMenuField
   *
   * Happy path — node references a menu containing one visible link;
   * getMenuField unwraps the single-reference list via
   * normalizeReturnValue and returns the menu items directly.
   */
  public function testReturnsItemsForReferencedMenu(): void {
    $menu = Menu::create(['id' => 'main_nav', 'label' => 'Main']);
    $menu->save();
    MenuLinkContent::create([
      'menu_name' => 'main_nav',
      'title' => 'Home',
      'link' => ['uri' => 'internal:/'],
      'enabled' => 1,
    ])->save();

    $this->attachField('menu', 'entity_reference', ['target_type' => 'menu']);

    $node = $this->createTestNode([
      'field_menu' => ['target_id' => 'main_nav'],
    ]);

    $items = $this->entityHelper->getMenuField($node, 'field_menu');

    $this->assertIsArray($items);
    $this->assertNotEmpty($items);
    $first = reset($items);
    $this->assertSame('Home', $first['title']);
  }

  /**
   * @covers ::getMenuField
   *
   * Missing field name short-circuits via validateField and returns
   * FALSE — the documented signal for "field not present on entity".
   */
  public function testReturnsFalseForMissingField(): void {
    $node = $this->createTestNode();

    $this->assertFalse($this->entityHelper->getMenuField($node, 'field_no_such_field'));
  }

}
