<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Unit\Paragraphs;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\drupal_kit_paragraphs\Mapping\ParagraphMapping;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the mutable mapping object.
 *
 * @coversDefaultClass \Drupal\drupal_kit_paragraphs\Mapping\ParagraphMapping
 * @group drupal_kit
 */
class ParagraphMappingTest extends TestCase {

  /**
   * Get, set, has and remove work on top-level keys.
   *
   * @covers ::get
   * @covers ::set
   * @covers ::has
   * @covers ::remove
   */
  public function testGetSetRemove(): void {
    $mapping = new ParagraphMapping('contact');
    $this->assertSame('x', $mapping->get('missing', 'x'));
    $mapping->set('phone', '123')->set('empty', NULL);
    $this->assertSame('123', $mapping->get('phone'));
    $this->assertTrue($mapping->has('empty'));
    $mapping->remove('phone');
    $this->assertFalse($mapping->has('phone'));
    $this->assertSame(['empty' => NULL], $mapping->getData());
  }

  /**
   * ReplaceData swaps the whole array.
   *
   * @covers ::replaceData
   */
  public function testReplaceData(): void {
    $mapping = new ParagraphMapping('contact');
    $mapping->set('a', 1)->replaceData(['b' => 2]);
    $this->assertSame(['b' => 2], $mapping->getData());
  }

  /**
   * The template defaults to the component and can change.
   *
   * @covers ::getTemplate
   * @covers ::setTemplate
   * @covers ::setComponent
   */
  public function testTemplate(): void {
    $mapping = new ParagraphMapping();
    $this->assertTrue($mapping->isEmpty());
    $mapping->setComponent('contact');
    $this->assertFalse($mapping->isEmpty());
    $this->assertSame('contact', $mapping->getTemplate());
    $mapping->setTemplate('alert');
    $this->assertSame('alert', $mapping->getTemplate());
    $this->assertSame('contact', $mapping->getComponent());
  }

  /**
   * Cacheable dependencies merge.
   *
   * @covers ::addCacheableDependency
   * @covers ::getCacheableMetadata
   */
  public function testCacheability(): void {
    $mapping = new ParagraphMapping('contact');
    $mapping->addCacheableDependency((new CacheableMetadata())->setCacheTags(['a'])->setCacheContexts(['theme'])->setCacheMaxAge(300));
    $mapping->addCacheableDependency((new CacheableMetadata())->setCacheTags(['b'])->setCacheMaxAge(60));
    $metadata = $mapping->getCacheableMetadata();
    $this->assertEqualsCanonicalizing(['a', 'b'], $metadata->getCacheTags());
    $this->assertSame(['theme'], $metadata->getCacheContexts());
    $this->assertSame(60, $metadata->getCacheMaxAge());
  }

  /**
   * The render array carries template, content and cacheability.
   *
   * @covers ::toRenderArray
   */
  public function testRenderArray(): void {
    $mapping = new ParagraphMapping('article-video');
    $mapping->set('video', 'x')->addCacheableDependency((new CacheableMetadata())->setCacheTags(['paragraph:1']));
    $build = $mapping->toRenderArray();
    $this->assertSame('custom_component', $build['#theme']);
    $this->assertSame('article-video', $build['#template']);
    $this->assertSame(['video' => 'x'], $build['#content']);
    $this->assertSame(['paragraph:1'], $build['#cache']['tags']);
  }

  /**
   * An empty mapping renders only cacheability.
   *
   * @covers ::toRenderArray
   */
  public function testEmptyRenderArray(): void {
    $mapping = new ParagraphMapping();
    $mapping->addCacheableDependency((new CacheableMetadata())->setCacheTags(['paragraph:1']));
    $build = $mapping->toRenderArray();
    $this->assertArrayNotHasKey('#theme', $build);
    $this->assertSame(['paragraph:1'], $build['#cache']['tags']);
  }

  /**
   * Diagnostics collect in order.
   *
   * @covers ::addDiagnostic
   * @covers ::getDiagnostics
   */
  public function testDiagnostics(): void {
    $mapping = new ParagraphMapping('contact');
    $mapping->addDiagnostic('a')->addDiagnostic('b');
    $this->assertSame(['a', 'b'], $mapping->getDiagnostics());
  }

  /**
   * The list helper adds the tag and the access contexts.
   *
   * @covers ::addListDependency
   * @covers ::hasListDependency
   */
  public function testListDependency(): void {
    $mapping = new ParagraphMapping('x');
    $this->assertFalse($mapping->hasListDependency());

    $mapping->addListDependency('node');
    $this->assertTrue($mapping->hasListDependency());
    $this->assertSame(['node_list'], $mapping->getCacheableMetadata()->getCacheTags());
    $this->assertContains('user.node_grants:view', $mapping->getCacheableMetadata()->getCacheContexts());

    $narrow = (new ParagraphMapping('x'))->addListDependency('node', 'company', 'product');
    $this->assertSame(['node_list:company', 'node_list:product'], $narrow->getCacheableMetadata()->getCacheTags());

    $other = (new ParagraphMapping('x'))->addListDependency('taxonomy_term', 'topic');
    $this->assertSame(['taxonomy_term_list:topic'], $other->getCacheableMetadata()->getCacheTags());
    $this->assertSame(['user.permissions'], $other->getCacheableMetadata()->getCacheContexts());
  }

  /**
   * A mapping that cannot be cached counts as handled.
   *
   * @covers ::hasListDependency
   */
  public function testUncacheableCountsAsListDependency(): void {
    $mapping = (new ParagraphMapping('x'))->addCacheableDependency((new CacheableMetadata())->setCacheMaxAge(0));
    $this->assertTrue($mapping->hasListDependency());
  }

}
