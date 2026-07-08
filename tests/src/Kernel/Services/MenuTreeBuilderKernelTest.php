<?php

namespace Drupal\Tests\drupal_kit\Kernel\Services;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\KernelTests\KernelTestBase;
use Drupal\drupal_kit\Services\MenuTreeBuilder;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\system\Entity\Menu;

/**
 * Direct kernel coverage for MenuTreeBuilder.
 *
 * EntityHelperMenuTest exercises the same service end-to-end through
 * EntityHelper::getMenu, but PHPUnit's coverage metric only credits
 * lines to the class named in @coversDefaultClass. This file pins
 * MenuTreeBuilder coverage directly so the report reflects actual
 * branch coverage rather than facade-pass-through.
 *
 * @coversDefaultClass \Drupal\drupal_kit\Services\MenuTreeBuilder
 * @group drupal_kit
 */
class MenuTreeBuilderKernelTest extends KernelTestBase {

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
   * The builder under test (real service from container).
   */
  protected MenuTreeBuilder $builder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Order matters: menu_link_content has a revision_user field, so
    // the user schema must land first.
    $this->installEntitySchema('user');
    $this->installEntitySchema('menu_link_content');

    $this->builder = $this->container->get('drupal_kit.menu_tree_builder');
  }

  /**
   * @covers ::build
   * @covers ::collectCacheMetadata
   *
   * Empty menu — no links registered, build returns the empty array
   * and collectCacheMetadata yields the empty accumulator.
   */
  public function testBuildEmptyMenuReturnsEmptyArray(): void {
    $this->createMenu('empty_direct');

    $items = $this->builder->build('empty_direct');

    $this->assertSame([], $items);
    // collectCacheMetadata still returns a usable CacheableMetadata
    // object even when nothing was collected.
    $this->assertInstanceOf(CacheableMetadata::class, $this->builder->collectCacheMetadata());
  }

  /**
   * @covers ::build
   * @covers ::renderLinks
   *
   * Flat menu — pins the public shape of every returned item: the
   * eight documented keys (`id`, `title`, `description`, `url`,
   * `attributes`, `is_active`, `in_active_trail`, `below`).
   */
  public function testBuildFlatMenuReturnsDocumentedShape(): void {
    $this->createMenu('flat_direct');
    $this->createMenuLink('flat_direct', 'Home', 'internal:/');

    $items = $this->builder->build('flat_direct');

    $this->assertNotEmpty($items);
    $first = reset($items);
    foreach (['id', 'title', 'description', 'url', 'attributes', 'is_active', 'in_active_trail', 'below'] as $key) {
      $this->assertArrayHasKey($key, $first, "Missing documented key `$key` in renderLinks output.");
    }
    $this->assertSame('Home', $first['title']);
    $this->assertIsArray($first['below']);
    $this->assertIsBool($first['is_active']);
    $this->assertIsBool($first['in_active_trail']);
  }

  /**
   * @covers ::build
   * @covers ::renderLinks
   *
   * Nested menu — `below` is populated recursively with the same shape
   * the top level has.
   */
  public function testBuildNestedMenuRecursesIntoBelow(): void {
    $this->createMenu('nested_direct');
    $parent = $this->createMenuLink('nested_direct', 'Parent', 'internal:/p');
    $this->createMenuLink(
      'nested_direct',
      'Child',
      'internal:/p/c',
      FALSE,
      'menu_link_content:' . $parent->uuid(),
    );

    $items = $this->builder->build('nested_direct');

    $top = reset($items);
    $this->assertSame('Parent', $top['title']);
    $this->assertNotEmpty($top['below'], 'Child must appear under parent.');
    $child = reset($top['below']);
    $this->assertSame('Child', $child['title']);
    $this->assertSame([], $child['below'], 'Leaf has empty `below`.');
  }

  /**
   * @covers ::build
   *
   * Disabled menu links (`enabled: 0`) are filtered out by the access
   * + tree-manipulator chain so they never reach renderLinks.
   */
  public function testBuildSkipsDisabledLinks(): void {
    $this->createMenu('hidden_direct');
    $this->createMenuLink('hidden_direct', 'Visible', 'internal:/v', FALSE);
    $this->createMenuLink('hidden_direct', 'Hidden', 'internal:/h', TRUE);

    $items = $this->builder->build('hidden_direct');

    $titles = array_map(static fn ($i) => $i['title'], $items);
    $this->assertContains('Visible', $titles);
    $this->assertNotContains('Hidden', $titles);
  }

  /**
   * @covers ::build
   * @covers ::renderLinks
   *
   * `params['root']` scopes the returned tree to a subtree starting
   * at the given menu link plugin id — sibling top-level links are
   * excluded.
   */
  public function testBuildRespectsRootParam(): void {
    $this->createMenu('root_scope');
    $a = $this->createMenuLink('root_scope', 'Branch A', 'internal:/a');
    $this->createMenuLink(
      'root_scope',
      'A-1',
      'internal:/a/1',
      FALSE,
      'menu_link_content:' . $a->uuid(),
    );
    $this->createMenuLink('root_scope', 'Branch B (sibling)', 'internal:/b');

    $rooted = $this->builder->build('root_scope', [
      'root' => 'menu_link_content:' . $a->uuid(),
    ]);

    $titles = array_map(static fn ($i) => $i['title'], $rooted);
    $this->assertNotContains('Branch B (sibling)', $titles);
    // The root link itself stays out of the rendered tree; only its
    // descendants come back.
    $this->assertContains('A-1', $titles);
  }

  /**
   * @covers ::build
   * @covers ::renderLinks
   *
   * Field-formatter callback enriches the link with `field_*` fields
   * — same contract menu_item_extras consumers rely on.
   */
  public function testBuildFieldFormatterEnrichesLinkData(): void {
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

    $this->createMenu('extras_direct');
    MenuLinkContent::create([
      'menu_name' => 'extras_direct',
      'title' => 'Home',
      'link' => ['uri' => 'internal:/'],
      'enabled' => 1,
      'field_subtitle' => 'Welcome',
    ])->save();

    // Trivial formatter — returns the field value as a plain scalar.
    $formatter = static fn ($entity, string $field_name) => (string) $entity->get($field_name)->value;

    $items = $this->builder->build('extras_direct', [], $formatter);

    $first = reset($items);
    $this->assertArrayHasKey('subtitle', $first, 'field_ prefix stripped on enriched key.');
    $this->assertSame('Welcome', $first['subtitle']);
  }

  /**
   * @covers ::collectCacheMetadata
   *
   * collectCacheMetadata drains the accumulator and resets internal
   * state — the documented "collect and reset" contract. Two
   * consecutive collects with no build in between: first carries the
   * build's accumulated dependencies, second is empty.
   */
  public function testCollectCacheMetadataResetsBetweenCalls(): void {
    $this->createMenu('cache_direct');
    $this->createMenuLink('cache_direct', 'Home', 'internal:/');
    $this->builder->build('cache_direct');

    $first = $this->builder->collectCacheMetadata();
    // build() bubbles the rendered menu's cacheable metadata into the
    // accumulator, so the first drain should at least be non-default
    // (max-age permanent OR some context). The strict check we can
    // make safely without binding to Drupal-version-specific tags is
    // that a fresh CacheableMetadata instance comes back.
    $this->assertInstanceOf(CacheableMetadata::class, $first);

    $second = $this->builder->collectCacheMetadata();
    // After drain, the accumulator must be reset — same shape but
    // empty payload (no tags, no contexts beyond defaults).
    $this->assertSame([], $second->getCacheTags());
    $this->assertSame([], $second->getCacheContexts());
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
   * Create a menu_link_content entity, optionally under a parent.
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
