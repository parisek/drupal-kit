<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Unit\Services;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheableMetadata;
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
use Drupal\drupal_kit\Services\CacheScope;
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

    $this->helper = $this->newHelper();
  }

  /**
   * Builds a real helper with mocked dependencies.
   */
  private function newHelper(): EntityHelper {
    return new EntityHelper(
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
   * Asserts that the helper state is the expected one.
   *
   * @param string[] $tags
   *   The tags the accumulator must hold now.
   */
  private function assertAccumulator(array $tags): void {
    // Draining is the only reader. Put the tags back afterwards.
    $this->assertEqualsCanonicalizing($tags, $this->helper->collectCacheMetadata()->getCacheTags());
    $this->helper->addCacheTags($tags);
  }

  /**
   * A closed handle cannot close again, even if a new scope took its place.
   *
   * @covers ::endCacheScope
   */
  public function testStaleHandleIsRejected(): void {
    $stale = $this->helper->beginCacheScope();
    $this->helper->endCacheScope($stale);
    $fresh = $this->helper->beginCacheScope();
    $this->helper->addCacheTags(['fresh']);

    try {
      $this->helper->endCacheScope($stale);
      $this->fail('Expected an exception.');
    }
    catch (\LogicException) {
      $this->addToAssertionCount(1);
    }

    $this->assertAccumulator(['fresh']);
    $this->assertSame(['fresh'], $this->helper->endCacheScope($fresh)->getCacheTags());
  }

  /**
   * A double close throws.
   *
   * @covers ::endCacheScope
   */
  public function testDoubleCloseThrows(): void {
    $scope = $this->helper->beginCacheScope();
    $this->helper->endCacheScope($scope);
    $this->expectException(\LogicException::class);
    $this->helper->endCacheScope($scope);
  }

  /**
   * An ancestor handle does not absorb the inner scopes. Nothing changes.
   *
   * @covers ::endCacheScope
   */
  public function testAncestorHandleIsRejectedWithoutChange(): void {
    $this->helper->addCacheTags(['a']);
    $outer = $this->helper->beginCacheScope();
    $this->helper->addCacheTags(['b']);
    $inner = $this->helper->beginCacheScope();
    $this->helper->addCacheTags(['c']);

    try {
      $this->helper->endCacheScope($outer);
      $this->fail('Expected an exception.');
    }
    catch (\LogicException) {
      $this->addToAssertionCount(1);
    }

    $this->assertAccumulator(['c']);
    $this->assertSame(['c'], $this->helper->endCacheScope($inner)->getCacheTags());
    $this->assertSame(['b'], $this->helper->endCacheScope($outer)->getCacheTags());
    $this->assertAccumulator(['a']);
  }

  /**
   * A forged handle and a handle of another helper are rejected.
   *
   * @covers ::endCacheScope
   */
  public function testForgedAndForeignHandlesAreRejected(): void {
    $own = $this->helper->beginCacheScope();
    $this->helper->addCacheTags(['own']);
    $other = $this->newHelper();
    $foreign = $other->beginCacheScope();

    foreach ([new CacheScope(1, NULL), $foreign] as $handle) {
      try {
        $this->helper->endCacheScope($handle);
        $this->fail('Expected an exception.');
      }
      catch (\LogicException) {
        $this->addToAssertionCount(1);
      }
    }

    $this->assertAccumulator(['own']);
    $this->assertSame(['own'], $this->helper->endCacheScope($own)->getCacheTags());
    $this->assertSame([], $other->endCacheScope($foreign)->getCacheTags());
  }

  /**
   * Closing with no scope open throws.
   *
   * @covers ::endCacheScope
   */
  public function testCloseWithNothingOpenThrows(): void {
    $this->expectException(\LogicException::class);
    $this->helper->endCacheScope(new CacheScope(1, NULL));
  }

  /**
   * AbandonCacheScopesAbove drops inner scopes and keeps ours.
   *
   * @covers ::abandonCacheScopesAbove
   */
  public function testAbandonDropsInnerScopes(): void {
    $this->helper->addCacheTags(['a']);
    $outer = $this->helper->beginCacheScope();
    $this->helper->addCacheTags(['b']);
    $this->helper->beginCacheScope();
    $this->helper->addCacheTags(['c']);
    $this->helper->beginCacheScope();
    $this->helper->addCacheTags(['d']);

    $this->helper->abandonCacheScopesAbove($outer);

    $this->assertAccumulator(['b']);
    $this->assertSame(['b'], $this->helper->endCacheScope($outer)->getCacheTags());
    $this->assertAccumulator(['a']);
  }

  /**
   * Merges metadata into the helper's current accumulator.
   *
   * EntityHelper has no public way to add contexts or max-age; real callers
   * add them through the entities they load.
   */
  private function addMetadata(CacheableMetadata $metadata): void {
    $property = new \ReflectionProperty($this->helper, 'cacheMetadata');
    $property->setValue($this->helper, $property->getValue($this->helper)->merge($metadata));
  }

  /**
   * With $keep, closed inner scopes merge downward, depth two, all fields.
   *
   * @covers ::abandonCacheScopesAbove
   */
  public function testAbandonWithKeepMergesDownward(): void {
    $this->helper->addCacheTags(['a']);
    $outer = $this->helper->beginCacheScope();
    $this->helper->addCacheTags(['b']);
    $this->helper->beginCacheScope();
    $this->helper->addCacheTags(['c']);
    $this->addMetadata((new CacheableMetadata())->setCacheContexts(['user']));
    $this->helper->beginCacheScope();
    $this->helper->addCacheTags(['d']);
    $this->addMetadata((new CacheableMetadata())->setCacheMaxAge(60));

    $this->assertSame(2, $this->helper->abandonCacheScopesAbove($outer, TRUE));

    $collected = $this->helper->endCacheScope($outer);
    $this->assertEqualsCanonicalizing(['b', 'c', 'd'], $collected->getCacheTags());
    $this->assertSame(['user'], $collected->getCacheContexts());
    $this->assertSame(60, $collected->getCacheMaxAge());
    $this->assertAccumulator(['a']);
  }

  /**
   * Abandon with nothing above changes nothing, and rejects a closed scope.
   *
   * @covers ::abandonCacheScopesAbove
   */
  public function testAbandonEdgeCases(): void {
    $scope = $this->helper->beginCacheScope();
    $this->helper->addCacheTags(['x']);
    $this->helper->abandonCacheScopesAbove($scope);
    $this->assertAccumulator(['x']);
    $this->helper->endCacheScope($scope);

    $this->expectException(\LogicException::class);
    $this->helper->abandonCacheScopesAbove($scope);
  }

  /**
   * Two interleaved Fibers: the one that is not innermost cannot close.
   *
   * Fiber A opens a scope and suspends. Fiber B opens a scope above it. A
   * resumes and tries to close: it is not the innermost scope, so it gets an
   * error. B cannot close A's scope either. No tag leaks or vanishes.
   *
   * @covers ::endCacheScope
   */
  public function testInterleavedFibersGetErrors(): void {
    $helper = $this->helper;
    /** @var \ArrayObject<int, string> $errors */
    $errors = new \ArrayObject();
    /** @var \ArrayObject<string, string[]> $result */
    $result = new \ArrayObject();

    $a = new \Fiber(function () use ($helper, $errors, $result): void {
      $scope = $helper->beginCacheScope();
      $helper->addCacheTags(['a']);
      \Fiber::suspend($scope);
      try {
        $helper->endCacheScope($scope);
      }
      catch (\LogicException $e) {
        $errors[] = 'a-early';
      }
      \Fiber::suspend();
      $result['a'] = $helper->endCacheScope($scope)->getCacheTags();
    });
    $scope_a = $a->start();

    $b = new \Fiber(function () use ($helper, $scope_a, $errors, $result): void {
      $scope = $helper->beginCacheScope();
      $helper->addCacheTags(['b']);
      \Fiber::suspend();
      try {
        $helper->endCacheScope($scope_a);
      }
      catch (\LogicException $e) {
        $errors[] = 'b-foreign';
      }
      $result['b'] = $helper->endCacheScope($scope)->getCacheTags();
    });
    $b->start();

    // A resumes while B's scope is above its own: it must fail.
    $a->resume();
    $this->assertSame(['a-early'], $errors->getArrayCopy());
    // B tries A's scope from the wrong Fiber and then ends its own.
    $b->resume();
    $this->assertSame(['a-early', 'b-foreign'], $errors->getArrayCopy());
    $this->assertSame(['b'], $result['b']);
    // A can close now that B is gone.
    $a->resume();
    $this->assertSame(['a'], $result['a']);
    $this->assertAccumulator([]);
  }

  /**
   * A scope cannot close from another Fiber, also when it is the innermost.
   *
   * @covers ::endCacheScope
   */
  public function testScopeCannotCloseFromAnotherFiber(): void {
    $scope = $this->helper->beginCacheScope();
    $this->helper->addCacheTags(['x']);
    $helper = $this->helper;
    $fiber = new \Fiber(function () use ($helper, $scope): string {
      try {
        $helper->endCacheScope($scope);
      }
      catch (\LogicException $e) {
        return $e->getMessage();
      }
      return 'closed';
    });
    $fiber->start();

    $this->assertStringContainsString('another Fiber', (string) $fiber->getReturn());
    $this->assertAccumulator(['x']);
    $this->assertSame(['x'], $this->helper->endCacheScope($scope)->getCacheTags());
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
