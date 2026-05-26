<?php

namespace Drupal\Tests\custom_components\Unit\Services;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\custom_components\Services\MenuActiveTrailResolver;
use Drupal\custom_components\Services\MenuTreeBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Unit tests for MenuTreeBuilder.
 *
 * Moved from EntityHelperTest::testGetMenu* in #6b. The end-to-end
 * EntityHelperMenuTest kernel test continues to assert the same
 * public contract.
 *
 * @coversDefaultClass \Drupal\custom_components\Services\MenuTreeBuilder
 * @group custom_components
 */
class MenuTreeBuilderTest extends TestCase {

  protected MenuLinkTreeInterface $menuLinkTree;

  protected MenuActiveTrailResolver $activeTrailResolver;

  protected LanguageManagerInterface $languageManager;

  protected EntityTypeManagerInterface $entityTypeManager;

  protected RequestStack $requestStack;

  protected function setUp(): void {
    parent::setUp();

    $this->menuLinkTree = $this->createMock(MenuLinkTreeInterface::class);
    $this->menuLinkTree->method('getCurrentRouteMenuTreeParameters')
      ->willReturn(new MenuTreeParameters());

    $this->activeTrailResolver = $this->createMock(MenuActiveTrailResolver::class);
    $this->activeTrailResolver->method('getActiveTrailIds')->willReturn([]);

    $this->languageManager = $this->createMock(LanguageManagerInterface::class);
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');
    $this->languageManager->method('getCurrentLanguage')->willReturn($language);

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    $this->requestStack = $this->createMock(RequestStack::class);
  }

  /**
   * @covers ::build
   * @covers ::collectCacheMetadata
   *
   * MenuLinkTree::build() returns a render array with '#cache' carrying
   * access-check contexts (user.permissions) + tags for route-bound
   * links. The builder must forward that into the collector.
   */
  public function testBubblesTreeCacheMetadataIntoCollector(): void {
    $this->menuLinkTree->method('load')->willReturn([]);
    $this->menuLinkTree->method('transform')->willReturn([]);
    $this->menuLinkTree->method('build')->willReturn([
      '#items' => [],
      '#cache' => [
        'contexts' => ['user.permissions'],
        'tags' => ['node:42'],
        'max-age' => Cache::PERMANENT,
      ],
    ]);

    $builder = $this->newBuilder();

    $items = $builder->build('main');
    $this->assertSame([], $items);

    $cache = $builder->collectCacheMetadata();
    $this->assertContains(
      'user.permissions',
      $cache->getCacheContexts(),
      'user.permissions must bubble into the collector so blocks do not cache across roles',
    );
    $this->assertContains(
      'node:42',
      $cache->getCacheTags(),
      'Tags from access checks on route-bound links must bubble',
    );
  }

  /**
   * @covers ::collectCacheMetadata
   */
  public function testCollectCacheMetadataResetsBetweenCalls(): void {
    $this->menuLinkTree->method('load')->willReturn([]);
    $this->menuLinkTree->method('transform')->willReturn([]);
    $this->menuLinkTree->method('build')->willReturn([
      '#items' => [],
      '#cache' => [
        'contexts' => ['user.permissions'],
        'tags' => ['node:1'],
      ],
    ]);

    $builder = $this->newBuilder();

    $builder->build('main');
    $first = $builder->collectCacheMetadata();
    $this->assertContains('node:1', $first->getCacheTags());

    // Second collect must be empty — collectCacheMetadata resets state.
    $second = $builder->collectCacheMetadata();
    $this->assertEmpty($second->getCacheTags());
    $this->assertEmpty($second->getCacheContexts());
  }

  protected function newBuilder(): MenuTreeBuilder {
    return new MenuTreeBuilder(
      $this->menuLinkTree,
      $this->activeTrailResolver,
      $this->languageManager,
      $this->entityTypeManager,
      $this->requestStack,
    );
  }

}
