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

  protected EntityTypeManagerInterface $entityTypeManager;

  protected LanguageManagerInterface $languageManager;

  protected TaxonomyTreeBuilder $builder;

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
   */
  protected function makeTerm(int $tid, string $label, bool $published = TRUE): TermInterface {
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

    // Parent field defaults to empty (root) so non-nested calls aren't
    // affected; nested-mode tests can override this.
    $parent_list = $this->createMock(FieldItemListInterface::class);
    $parent_list->method('getValue')->willReturn([]);
    $term->method('get')->with('parent')->willReturn($parent_list);

    return $term;
  }

}
