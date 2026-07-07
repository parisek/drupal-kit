<?php

namespace Drupal\Tests\custom_components\Unit\Services;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Url;
use Drupal\custom_components\Services\TaxonomyTreeBuilder;
use Drupal\taxonomy\TermInterface;
use Drupal\taxonomy\TermStorageInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TaxonomyTreeBuilder.
 *
 * These tests were originally embedded in EntityHelperTest as
 * getTaxonomy* tests. They moved here when #6 extracted the builder
 * from EntityHelper; the kernel test EntityHelperTaxonomyTest
 * continues to assert on the same public contract end-to-end.
 *
 * @coversDefaultClass \Drupal\custom_components\Services\TaxonomyTreeBuilder
 * @group custom_components
 */
class TaxonomyTreeBuilderTest extends TestCase {

  /**
   * Mocked entity type manager passed to the builder under test.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Mocked language manager, stubbed to report English as current.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * The TaxonomyTreeBuilder under test.
   *
   * @var \Drupal\custom_components\Services\TaxonomyTreeBuilder
   */
  protected TaxonomyTreeBuilder $builder;

  /**
   * Instantiates the builder under test with mocked dependencies.
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->languageManager = $this->createMock(LanguageManagerInterface::class);

    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');
    $this->languageManager->method('getCurrentLanguage')->willReturn($language);

    $this->builder = new TaxonomyTreeBuilder(
      $this->entityTypeManager,
      $this->languageManager,
    );
  }

  /**
   * @covers ::build
   * @covers ::collectCacheMetadata
   */
  public function testBubblesTermCacheTagsIntoMetadata(): void {
    $this->stubStorage([
      $this->makeTerm(1, 'Alpha'),
      $this->makeTerm(2, 'Beta'),
    ]);

    $items = $this->builder->build('support_category');

    $this->assertCount(2, $items);
    $this->assertSame('Alpha', $items[0]['title']);
    $this->assertSame('Beta', $items[1]['title']);

    $tags = $this->builder->collectCacheMetadata()->getCacheTags();
    $this->assertContains('taxonomy_term:1', $tags);
    $this->assertContains('taxonomy_term:2', $tags);
  }

  /**
   * @covers ::build
   *
   * Drupal core's "any term in this vocabulary" cache tag format —
   * what Term save/delete invalidates via EntityBase's
   * getListCacheTagsToInvalidate().
   */
  public function testAddsBundleListCacheTag(): void {
    $this->stubStorage([$this->makeTerm(1, 'Alpha')]);

    $this->builder->build('support_category');
    $tags = $this->builder->collectCacheMetadata()->getCacheTags();

    $this->assertContains('taxonomy_term_list:support_category', $tags);
  }

  /**
   * @covers ::build
   *
   * Unpublished terms drop out of the returned items AND their tag is
   * not collected (the term iteration short-circuits before
   * addCacheableDependency runs). The vocabulary list tag still fires,
   * so consumers correctly invalidate if the term is published later.
   */
  public function testSkipsUnpublishedTermsButStillCollectsListTag(): void {
    $this->stubStorage([
      $this->makeTerm(1, 'Published', TRUE),
      $this->makeTerm(2, 'Unpublished', FALSE),
    ]);

    $items = $this->builder->build('support_category');

    $this->assertCount(1, $items);
    $this->assertSame('Published', $items[0]['title']);

    $tags = $this->builder->collectCacheMetadata()->getCacheTags();
    $this->assertContains('taxonomy_term:1', $tags);
    $this->assertNotContains('taxonomy_term:2', $tags);
    $this->assertContains('taxonomy_term_list:support_category', $tags);
  }

  /**
   * @covers ::collectCacheMetadata
   */
  public function testCollectCacheMetadataResetsBetweenCalls(): void {
    $this->stubStorage([$this->makeTerm(1, 'Alpha')]);

    $this->builder->build('support_category');
    $first = $this->builder->collectCacheMetadata()->getCacheTags();
    $this->assertContains('taxonomy_term:1', $first);

    // Second collect (without another build) must be empty — the
    // collector resets on drain.
    $second = $this->builder->collectCacheMetadata()->getCacheTags();
    $this->assertEmpty($second);
  }

  /**
   * @covers ::build
   * @covers ::buildTermTree
   *
   * Nested mode (`params['nested'] === TRUE`) routes through the
   * recursive buildTermTree helper. Each item carries a `children`
   * key holding child terms with the same shape; leaves get an empty
   * `children` array.
   */
  public function testNestedTreeReturnsHierarchicalStructure(): void {
    // Tree: root1 → child1a (leaf) + child1b (with grandchild1ba);
    // root2 (leaf).
    $this->stubStorage([
      $this->makeTerm(1, 'Root 1', TRUE, 0),
      $this->makeTerm(11, 'Child 1a', TRUE, 1),
      $this->makeTerm(12, 'Child 1b', TRUE, 1),
      $this->makeTerm(121, 'Grandchild 1ba', TRUE, 12),
      $this->makeTerm(2, 'Root 2', TRUE, 0),
    ]);

    $tree = $this->builder->build('section', ['nested' => TRUE]);

    $this->assertCount(2, $tree, 'Two roots at the top level.');
    $this->assertSame('Root 1', $tree[0]['title']);
    $this->assertSame('Root 2', $tree[1]['title']);

    // Root 1 has two children.
    $this->assertCount(2, $tree[0]['children']);
    $this->assertSame('Child 1a', $tree[0]['children'][0]['title']);
    $this->assertSame('Child 1b', $tree[0]['children'][1]['title']);
    // Child 1a is a leaf.
    $this->assertSame([], $tree[0]['children'][0]['children']);
    // Child 1b has a grandchild.
    $this->assertCount(1, $tree[0]['children'][1]['children']);
    $this->assertSame('Grandchild 1ba', $tree[0]['children'][1]['children'][0]['title']);
    // Root 2 is a leaf.
    $this->assertSame([], $tree[1]['children']);
  }

  /**
   * @covers ::buildTermTree
   *
   * buildTermTree returns an empty array when a parent_id has no
   * children registered in the children_map — exercises the
   * `!isset($children_map[$parent_id])` early return that bounds the
   * recursion at the leaves.
   */
  public function testNestedTreeReturnsEmptyChildrenForLeaf(): void {
    $this->stubStorage([
      $this->makeTerm(1, 'Solo Root', TRUE, 0),
    ]);

    $tree = $this->builder->build('section', ['nested' => TRUE]);

    $this->assertCount(1, $tree);
    $this->assertArrayHasKey('children', $tree[0]);
    $this->assertSame([], $tree[0]['children']);
  }

  /**
   * Build a stub term storage returning the given term tree.
   */
  protected function stubStorage(array $terms, string $entity_type_id = 'taxonomy_term'): void {
    $storage = $this->createMock(TermStorageInterface::class);
    $storage->method('loadTree')->willReturn($terms);
    $storage->method('getEntityTypeId')->willReturn($entity_type_id);
    $this->entityTypeManager
      ->method('getStorage')
      ->with('taxonomy_term')
      ->willReturn($storage);
  }

  /**
   * Mock a term with the minimum API the builder consumes.
   *
   * @param int $tid
   *   The term id.
   * @param string $label
   *   The term name/label.
   * @param bool $published
   *   Whether the term is published (default: TRUE).
   * @param int $parent
   *   Parent term id, or 0 for a root term. Nested-mode tests
   *   (`build(…, ['nested' => TRUE])`) read this via
   *   `$term->get('parent')->getValue()[0]['target_id']`.
   */
  protected function makeTerm(int $tid, string $label, bool $published = TRUE, int $parent = 0): TermInterface {
    $term = $this->createMock(TermInterface::class);
    $term->method('id')->willReturn((string) $tid);
    $term->method('isPublished')->willReturn($published);
    $term->method('hasTranslation')->willReturn(TRUE);
    $term->method('getTranslation')->willReturnSelf();
    $term->method('label')->willReturn($label);
    $url = $this->createMock(Url::class);
    $url->method('toString')->willReturn('/taxonomy/term/' . $tid);
    $term->method('toUrl')->willReturn($url);
    $term->method('getCacheTags')->willReturn(['taxonomy_term:' . $tid]);
    $term->method('getCacheContexts')->willReturn([]);
    $term->method('getCacheMaxAge')->willReturn(Cache::PERMANENT);

    $parent_list = $this->createMock(FieldItemListInterface::class);
    $parent_list->method('getValue')->willReturn(
      $parent === 0 ? [] : [['target_id' => $parent]],
    );
    $term->method('get')->with('parent')->willReturn($parent_list);

    return $term;
  }

}
