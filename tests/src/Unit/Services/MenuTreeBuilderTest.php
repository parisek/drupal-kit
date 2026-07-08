<?php

namespace Drupal\Tests\drupal_kit\Unit\Services;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\drupal_kit\Services\MenuActiveTrailResolver;
use Drupal\drupal_kit\Services\MenuTreeBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Unit tests for MenuTreeBuilder.
 *
 * Moved from EntityHelperTest::testGetMenu* in #6b. The end-to-end
 * EntityHelperMenuTest kernel test continues to assert the same
 * public contract.
 *
 * @coversDefaultClass \Drupal\drupal_kit\Services\MenuTreeBuilder
 * @group drupal_kit
 */
class MenuTreeBuilderTest extends TestCase {

  /**
   * Mocked menu link tree, stubbed to return empty tree parameters.
   *
   * @var \Drupal\Core\Menu\MenuLinkTreeInterface
   */
  protected MenuLinkTreeInterface $menuLinkTree;

  /**
   * Mocked active trail resolver, stubbed to return no active trail.
   *
   * @var \Drupal\drupal_kit\Services\MenuActiveTrailResolver
   */
  protected MenuActiveTrailResolver $activeTrailResolver;

  /**
   * Mocked language manager, stubbed to report English as current.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * Mocked entity type manager passed to the builder under test.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Mocked request stack passed to the builder under test.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * Instantiates mocked dependencies and a minimal cache-contexts container.
   */
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

    // CacheableMetadata::createFromRenderArray() drains through
    // Cache::mergeContexts() which uses
    // \Drupal::service('cache_contexts_manager').
    // Without a container set up the test crashes the first time it runs in
    // a fresh process (CI order was hiding the gap via container leak from
    // EntityHelperTest's setUp).
    $cache_contexts_manager = $this->createMock(CacheContextsManager::class);
    $cache_contexts_manager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cache_contexts_manager);
    \Drupal::setContainer($container);
  }

  /**
   * Restores the container to its prior state after each test.
   */
  protected function tearDown(): void {
    if (\Drupal::hasContainer()) {
      \Drupal::unsetContainer();
    }
    parent::tearDown();
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

  /**
   * Builds a MenuTreeBuilder wired to this test's mocked dependencies.
   */
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
