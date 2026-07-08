<?php

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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Cache-tag accumulator + SVG facade dispatch on EntityHelper.
 *
 * - addCacheTags / collectCacheMetadata: the public cache-bubble API
 *   consumer code uses to merge tags from EntityHelper's internal
 *   accumulator into the response.
 * - getSvgViewBoxDimensions: a thin facade to the same-named method on
 *   MediaArrayBuilder (real parsing is exercised by
 *   MediaArrayBuilderSvgTest).
 *
 * @coversDefaultClass \Drupal\drupal_kit\Services\EntityHelper
 * @group drupal_kit
 */
class EntityHelperCacheAndSvgFacadeTest extends TestCase {

  /**
   * The EntityHelper under test.
   */
  protected EntityHelper $helper;

  /**
   * Mocked MediaArrayBuilder for the SVG facade test.
   *
   * @var \Drupal\drupal_kit\Services\MediaArrayBuilder&MockObject
   */
  protected MockObject $builder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->builder = $this->createMock(MediaArrayBuilder::class);

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
      $this->builder,
    );

    // CacheableMetadata::addCacheTags() goes through
    // cache_contexts_manager->assertValidTokens() for context merges.
    // Tag-only operations don't strictly need it, but install the stub
    // so the assertion path stays robust if Drupal core changes.
    $container = new ContainerBuilder();
    $cache_contexts_manager = $this->createMock(CacheContextsManager::class);
    $cache_contexts_manager->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $cache_contexts_manager);
    \Drupal::setContainer($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (\Drupal::hasContainer()) {
      \Drupal::unsetContainer();
    }
    parent::tearDown();
  }

  /**
   * @covers ::addCacheTags
   * @covers ::collectCacheMetadata
   *
   * Tags pushed via addCacheTags drain through collectCacheMetadata,
   * and collectCacheMetadata resets the internal accumulator on read
   * (the documented "collect and reset" contract).
   */
  public function testAddCacheTagsBubblesIntoCollectCacheMetadata(): void {
    $this->helper->addCacheTags(['node:42', 'config_pages:global']);
    $this->helper->addCacheTags(['node:99']);

    $first = $this->helper->collectCacheMetadata()->getCacheTags();
    sort($first);
    $this->assertSame(
      ['config_pages:global', 'node:42', 'node:99'],
      $first,
    );

    // Second collect must be empty — accumulator resets on collect.
    $this->assertSame([], $this->helper->collectCacheMetadata()->getCacheTags());
  }

  /**
   * @covers ::getSvgViewBoxDimensions
   *
   * Facade dispatch — forwards the URI to MediaArrayBuilder unchanged
   * and returns whatever the builder returns.
   */
  public function testGetSvgViewBoxDimensionsForwardsUriToMediaArrayBuilder(): void {
    $uri = 'public://icon.svg';
    $expected = ['width' => 24, 'height' => 24];

    $this->builder->expects($this->once())
      ->method('getSvgViewBoxDimensions')
      ->with($uri)
      ->willReturn($expected);

    $this->assertSame($expected, $this->helper->getSvgViewBoxDimensions($uri));
  }

  /**
   * @covers ::getSvgViewBoxDimensions
   *
   * NULL return passes through unchanged (the documented null-safe
   * shape from the underlying parser).
   */
  public function testGetSvgViewBoxDimensionsReturnsNullWhenBuilderReturnsNull(): void {
    $this->builder->method('getSvgViewBoxDimensions')->willReturn(NULL);

    $this->assertNull($this->helper->getSvgViewBoxDimensions('public://broken.svg'));
  }

}
