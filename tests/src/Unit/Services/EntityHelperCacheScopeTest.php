<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Unit\Services;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\drupal_kit\Services\EntityHelper;
use Drupal\drupal_kit\Services\MediaArrayBuilder;
use Drupal\drupal_kit\Services\MenuActiveTrailResolver;
use Drupal\drupal_kit\Services\MenuTreeBuilder;
use Drupal\drupal_kit\Services\TaxonomyTreeBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests for the scoped cache accumulator of EntityHelper.
 *
 * @coversDefaultClass \Drupal\drupal_kit\Services\EntityHelper
 * @group drupal_kit
 */
class EntityHelperCacheScopeTest extends TestCase {

  /**
   * The helper under test. Its accumulator is real state.
   */
  private EntityHelper $helper;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $contexts = $this->createMock(CacheContextsManager::class);
    $contexts->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $contexts);
    \Drupal::setContainer($container);

    $this->helper = new EntityHelper(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(RouteMatchInterface::class),
      $this->createMock(LanguageManagerInterface::class),
      $this->createMock(EntityRepositoryInterface::class),
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(Connection::class),
      $this->createMock(CacheBackendInterface::class),
      $this->createMock(MenuLinkTreeInterface::class),
      $this->createMock(FileUrlGeneratorInterface::class),
      $this->createMock(LoggerChannelFactoryInterface::class),
      $this->createMock(RendererInterface::class),
      $this->createMock(DateFormatterInterface::class),
      $this->createMock(ImageFactory::class),
      $this->createMock(RequestStack::class),
      $this->createMock(MenuActiveTrailResolver::class),
      $this->createMock(TaxonomyTreeBuilder::class),
      $this->createMock(MenuTreeBuilder::class),
      $this->createMock(MediaArrayBuilder::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * The scope returns only its own tags and restores the outer content.
   *
   * @covers ::beginCacheScope
   * @covers ::endCacheScope
   */
  public function testScopeIsolatesAndRestores(): void {
    $this->helper->addCacheTags(['outer']);
    $scope = $this->helper->beginCacheScope();
    $this->helper->addCacheTags(['inner']);

    $this->assertSame(['inner'], $this->helper->endCacheScope($scope)->getCacheTags());
    $this->assertSame(['outer'], $this->helper->collectCacheMetadata()->getCacheTags());
  }

  /**
   * A scope that starts empty sees none of the outer tags.
   *
   * @covers ::beginCacheScope
   */
  public function testScopeStartsEmpty(): void {
    $this->helper->addCacheTags(['outer']);
    $scope = $this->helper->beginCacheScope();
    $this->assertSame([], $this->helper->collectCacheMetadata()->getCacheTags());
    $this->helper->endCacheScope($scope);
  }

  /**
   * Scopes nest.
   *
   * @covers ::endCacheScope
   */
  public function testNestedScopes(): void {
    $this->helper->addCacheTags(['a']);
    $outer = $this->helper->beginCacheScope();
    $this->helper->addCacheTags(['b']);
    $inner = $this->helper->beginCacheScope();
    $this->helper->addCacheTags(['c']);

    $this->assertSame(['c'], $this->helper->endCacheScope($inner)->getCacheTags());
    $this->helper->addCacheTags(['d']);
    $this->assertEqualsCanonicalizing(['b', 'd'], $this->helper->endCacheScope($outer)->getCacheTags());
    $this->assertSame(['a'], $this->helper->collectCacheMetadata()->getCacheTags());
  }

  /**
   * An inner scope left open is closed with its outer one.
   *
   * @covers ::endCacheScope
   */
  public function testUnclosedInnerScopeJoinsOuterScope(): void {
    $this->helper->addCacheTags(['a']);
    $outer = $this->helper->beginCacheScope();
    $this->helper->addCacheTags(['b']);
    $this->helper->beginCacheScope();
    $this->helper->addCacheTags(['c']);

    $this->assertEqualsCanonicalizing(['b', 'c'], $this->helper->endCacheScope($outer)->getCacheTags());
    $this->assertSame(['a'], $this->helper->collectCacheMetadata()->getCacheTags());
  }

  /**
   * Ending a scope twice, or a bad token, is a programming error.
   *
   * @covers ::endCacheScope
   */
  public function testClosedScopeThrows(): void {
    $scope = $this->helper->beginCacheScope();
    $this->helper->endCacheScope($scope);
    $this->expectException(\LogicException::class);
    $this->helper->endCacheScope($scope);
  }

  /**
   * CollectCacheMetadata keeps draining and resetting, with no scope open.
   *
   * @covers ::collectCacheMetadata
   */
  public function testCollectBehaviorIsUnchanged(): void {
    $this->helper->addCacheTags(['x']);
    $this->assertSame(['x'], $this->helper->collectCacheMetadata()->getCacheTags());
    $this->assertSame([], $this->helper->collectCacheMetadata()->getCacheTags());
  }

}
