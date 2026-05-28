<?php

namespace Drupal\Tests\custom_components\Unit\Services;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Link;
use Drupal\Core\Menu\MenuActiveTrailInterface;
use Drupal\Core\Menu\MenuLinkInterface;
use Drupal\Core\Menu\MenuLinkManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\custom_components\Services\MenuActiveTrailResolver;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the MenuActiveTrailResolver service.
 *
 * @coversDefaultClass \Drupal\custom_components\Services\MenuActiveTrailResolver
 * @group custom_components
 */
class MenuActiveTrailResolverTest extends TestCase {

  /**
   * Mock of Drupal's native MenuActiveTrail service.
   *
   * @var \Drupal\Core\Menu\MenuActiveTrailInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  protected $menuActiveTrail;

  /**
   * Mock of the menu link plugin manager.
   *
   * @var \Drupal\Core\Menu\MenuLinkManagerInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  protected $menuLinkManager;

  /**
   * Mock of the breadcrumb chain manager (Drupal's @breadcrumb service).
   *
   * @var \Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  protected $breadcrumbBuilder;

  /**
   * Mock of the current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  protected $routeMatch;

  /**
   * The service under test.
   */
  protected MenuActiveTrailResolver $resolver;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->menuActiveTrail = $this->createMock(MenuActiveTrailInterface::class);
    $this->menuLinkManager = $this->createMock(MenuLinkManagerInterface::class);
    $this->breadcrumbBuilder = $this->createMock(BreadcrumbBuilderInterface::class);
    $this->routeMatch = $this->createMock(RouteMatchInterface::class);

    $this->resolver = new MenuActiveTrailResolver(
      $this->menuActiveTrail,
      $this->menuLinkManager,
      $this->breadcrumbBuilder,
      $this->routeMatch,
    );

    // Breadcrumb::addCacheTags() reaches into the global container for
    // cache_contexts_manager. Stub it so tests can build breadcrumbs.
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
    // Normalize global state rather than restoring whatever was there —
    // a captured "original" container could itself be a leaked stub from
    // a previously-run test, so re-installing it would just propagate the
    // leak. Drop to a clean null container instead; any downstream test
    // that needs a container must set one up in its own setUp().
    // Mirrors Drupal core's UnitTestCase::tearDown().
    if (\Drupal::hasContainer()) {
      \Drupal::unsetContainer();
    }
    parent::tearDown();
  }

  /**
   * Native trail with a real menu link should be returned unchanged.
   *
   * When the current route IS in the menu, MenuActiveTrail already does
   * the right thing — we delegate without fallback.
   *
   * @covers ::getActiveTrailIds
   */
  public function testNativeTrailReturnedWhenNonEmpty(): void {
    $native_trail = [
      'menu_link_content:abc' => 'menu_link_content:abc',
      '' => '',
    ];
    $this->menuActiveTrail
      ->method('getActiveTrailIds')
      ->with('main')
      ->willReturn($native_trail);

    // Breadcrumb must NOT be touched when native trail succeeds.
    $this->breadcrumbBuilder
      ->expects($this->never())
      ->method('build');

    $result = $this->resolver->getActiveTrailIds('main');
    $this->assertSame($native_trail, $result);
  }

  /**
   * Empty native trail + matching breadcrumb ancestor = trail from that link.
   *
   * @covers ::getActiveTrailIds
   * @covers ::pickShallowest
   * @covers ::buildTrailFromLink
   */
  public function testFallbackUsesDeepestBreadcrumbMatch(): void {
    $this->menuActiveTrail
      ->method('getActiveTrailIds')
      ->willReturn(['' => '']);

    // Build a breadcrumb: Home -> Parent (matches menu) -> Child (no menu).
    $home = $this->makeLink('<front>', []);
    $parent_url = $this->makeRoutedUrl('entity.node.canonical', ['node' => '10']);
    $parent = Link::fromTextAndUrl('Parent', $parent_url);
    $child_url = $this->makeRoutedUrl('entity.node.canonical', ['node' => '20']);
    $child = Link::fromTextAndUrl('Child', $child_url);

    $breadcrumb = new Breadcrumb();
    $breadcrumb->setLinks([$home, $parent, $child]);
    $this->breadcrumbBuilder->method('build')->willReturn($breadcrumb);

    // Child route has no menu link; parent route does.
    $this->menuLinkManager
      ->method('loadLinksByRoute')
      ->willReturnCallback(function ($route, $params, $menu) {
        if ($route === 'entity.node.canonical' && ($params['node'] ?? NULL) === '10') {
          return ['menu_link_content:parent' => $this->makeMenuLink('menu_link_content:parent', '')];
        }
        return [];
      });

    $result = $this->resolver->getActiveTrailIds('main');
    $this->assertSame([
      '' => '',
      'menu_link_content:parent' => 'menu_link_content:parent',
    ], $result);
  }

  /**
   * Multiple breadcrumb ancestors match menu links → deepest one wins.
   *
   * This is the core bug fix: the OLD logic would mark both as active.
   *
   * @covers ::getActiveTrailIds
   */
  public function testDeepestBreadcrumbMatchTakesPrecedence(): void {
    $this->menuActiveTrail
      ->method('getActiveTrailIds')
      ->willReturn(['' => '']);

    // Home -> grandparent (menu) -> parent (menu) -> current page.
    $home = $this->makeLink('<front>', []);
    $grandparent = Link::fromTextAndUrl('Grandparent', $this->makeRoutedUrl('entity.node.canonical', ['node' => '5']));
    $parent = Link::fromTextAndUrl('Parent', $this->makeRoutedUrl('entity.node.canonical', ['node' => '10']));
    $current = Link::fromTextAndUrl('Current', $this->makeRoutedUrl('entity.node.canonical', ['node' => '20']));

    $breadcrumb = new Breadcrumb();
    $breadcrumb->setLinks([$home, $grandparent, $parent, $current]);
    $this->breadcrumbBuilder->method('build')->willReturn($breadcrumb);

    // Both grandparent and parent routes have menu links — DIFFERENT siblings.
    $this->menuLinkManager
      ->method('loadLinksByRoute')
      ->willReturnCallback(function ($route, $params, $menu) {
        if (($params['node'] ?? NULL) === '5') {
          return ['menu_link_content:grandparent' => $this->makeMenuLink('menu_link_content:grandparent', '')];
        }
        if (($params['node'] ?? NULL) === '10') {
          return ['menu_link_content:parent' => $this->makeMenuLink('menu_link_content:parent', '')];
        }
        return [];
      });

    $result = $this->resolver->getActiveTrailIds('main');

    // Only the deepest match (parent) should be in the trail.
    $this->assertArrayHasKey('menu_link_content:parent', $result);
    $this->assertArrayNotHasKey('menu_link_content:grandparent', $result);
  }

  /**
   * When breadcrumb match has parents in the menu, full trail is built.
   *
   * @covers ::getActiveTrailIds
   * @covers ::pickShallowest
   * @covers ::buildTrailFromLink
   */
  public function testTrailIncludesMenuParents(): void {
    $this->menuActiveTrail
      ->method('getActiveTrailIds')
      ->willReturn(['' => '']);

    $breadcrumb = new Breadcrumb();
    $breadcrumb->setLinks([
      $this->makeLink('<front>', []),
      Link::fromTextAndUrl('Match', $this->makeRoutedUrl('entity.node.canonical', ['node' => '10'])),
    ]);
    $this->breadcrumbBuilder->method('build')->willReturn($breadcrumb);

    $child_link = $this->makeMenuLink('menu_link_content:child', 'menu_link_content:parent');
    $parent_link = $this->makeMenuLink('menu_link_content:parent', '');

    $this->menuLinkManager
      ->method('loadLinksByRoute')
      ->willReturn(['menu_link_content:child' => $child_link]);

    $this->menuLinkManager
      ->method('createInstance')
      ->with('menu_link_content:parent')
      ->willReturn($parent_link);

    $result = $this->resolver->getActiveTrailIds('main');
    $this->assertSame([
      '' => '',
      'menu_link_content:child' => 'menu_link_content:child',
      'menu_link_content:parent' => 'menu_link_content:parent',
    ], $result);
  }

  /**
   * No native trail + no breadcrumb match → returns root-only trail.
   *
   * @covers ::getActiveTrailIds
   */
  public function testReturnsRootOnlyWhenNoMatchAnywhere(): void {
    $this->menuActiveTrail
      ->method('getActiveTrailIds')
      ->willReturn(['' => '']);

    $breadcrumb = new Breadcrumb();
    $breadcrumb->setLinks([
      $this->makeLink('<front>', []),
      Link::fromTextAndUrl('Unmatched', $this->makeRoutedUrl('entity.node.canonical', ['node' => '99'])),
    ]);
    $this->breadcrumbBuilder->method('build')->willReturn($breadcrumb);

    $this->menuLinkManager
      ->method('loadLinksByRoute')
      ->willReturn([]);

    $result = $this->resolver->getActiveTrailIds('main');
    $this->assertSame(['' => ''], $result);
  }

  /**
   * Crumb with no real route name must not poison loadLinksByRoute.
   *
   * Drupal's "current page" breadcrumb crumb reports isRouted() === true but
   * returns an empty/<none> route name. Forwarding that to loadLinksByRoute
   * matches every <nolink> menu item (dropdown parents) and corrupts the
   * trail. The resolver must skip empty route names.
   *
   * @covers ::getActiveTrailIds
   */
  public function testSkipsCrumbWithEmptyRouteName(): void {
    $this->menuActiveTrail
      ->method('getActiveTrailIds')
      ->willReturn(['' => '']);

    $current_url = $this->getMockBuilder(Url::class)
      ->disableOriginalConstructor()
      ->getMock();
    $current_url->method('isRouted')->willReturn(TRUE);
    $current_url->method('getRouteName')->willReturn('');
    $current_url->method('getRouteParameters')->willReturn([]);

    $breadcrumb = new Breadcrumb();
    $breadcrumb->setLinks([
      $this->makeLink('<front>', []),
      Link::fromTextAndUrl('Parent', $this->makeRoutedUrl('entity.node.canonical', ['node' => '10'])),
      // Current page crumb — routed but with no route name.
      new Link('Current page', $current_url),
    ]);
    $this->breadcrumbBuilder->method('build')->willReturn($breadcrumb);

    // Empty route → would match many <nolink> items; resolver must skip it
    // and fall through to the Parent crumb.
    $this->menuLinkManager
      ->method('loadLinksByRoute')
      ->willReturnCallback(function ($route, $params, $menu) {
        if ($route === '') {
          // Simulate the wildcard explosion: 3 nolink menu items.
          return [
            'menu_link_content:kontakty' => $this->makeMenuLink('menu_link_content:kontakty', ''),
            'menu_link_content:formulare' => $this->makeMenuLink('menu_link_content:formulare', 'menu_link_content:kontakty'),
            'menu_link_content:servis' => $this->makeMenuLink('menu_link_content:servis', 'menu_link_content:kontakty'),
          ];
        }
        if ($route === 'entity.node.canonical' && ($params['node'] ?? NULL) === '10') {
          return ['menu_link_content:parent' => $this->makeMenuLink('menu_link_content:parent', '')];
        }
        return [];
      });

    $result = $this->resolver->getActiveTrailIds('main');

    $this->assertSame([
      '' => '',
      'menu_link_content:parent' => 'menu_link_content:parent',
    ], $result);
  }

  /**
   * Same route appears as both a top-level item and a nested duplicate.
   *
   * When loadLinksByRoute returns multiple matches for a single breadcrumb
   * crumb, the shallowest one (closest to menu root) should win — otherwise
   * a nested SEO duplicate could highlight the wrong top-level section.
   *
   * @covers ::getActiveTrailIds
   * @covers ::pickShallowest
   * @covers ::buildTrailFromLink
   */
  public function testPrefersShallowestMatchAmongDuplicates(): void {
    $this->menuActiveTrail
      ->method('getActiveTrailIds')
      ->willReturn(['' => '']);

    $breadcrumb = new Breadcrumb();
    $breadcrumb->setLinks([
      $this->makeLink('<front>', []),
      Link::fromTextAndUrl('Branches', $this->makeRoutedUrl('entity.node.canonical', ['node' => '20'])),
    ]);
    $this->breadcrumbBuilder->method('build')->willReturn($breadcrumb);

    // Two menu links point to node 20:
    // - top-level "Pobočky" (no parent)
    // - nested "Více než 70 poboček" under "Kontakty".
    $top_level = $this->makeMenuLink('menu_link_content:pobocky', '');
    $nested = $this->makeMenuLink('menu_link_content:vice_nez_70', 'menu_link_content:kontakty');

    // Return nested FIRST to prove sorting (not insertion order) wins.
    $this->menuLinkManager
      ->method('loadLinksByRoute')
      ->willReturn([
        'menu_link_content:vice_nez_70' => $nested,
        'menu_link_content:pobocky' => $top_level,
      ]);

    // Depth lookup: top-level has 1-element root path, nested has 3.
    $this->menuLinkManager
      ->method('getParentIds')
      ->willReturnMap([
        ['menu_link_content:pobocky', ['menu_link_content:pobocky']],
        ['menu_link_content:vice_nez_70', [
          'menu_link_content:vice_nez_70',
          'menu_link_content:kontakty',
        ],
        ],
      ]);

    $result = $this->resolver->getActiveTrailIds('main');

    $this->assertSame([
      '' => '',
      'menu_link_content:pobocky' => 'menu_link_content:pobocky',
    ], $result);
  }

  /**
   * Breadcrumb cache metadata bubbles into provided cacheability bag.
   *
   * @covers ::getActiveTrailIds
   */
  public function testBubblesBreadcrumbCacheability(): void {
    $this->menuActiveTrail
      ->method('getActiveTrailIds')
      ->willReturn(['' => '']);

    $breadcrumb = new Breadcrumb();
    $breadcrumb->setLinks([$this->makeLink('<front>', [])]);
    $breadcrumb->addCacheTags(['node:42', 'config_pages:global']);
    $breadcrumb->addCacheContexts(['route']);
    $this->breadcrumbBuilder->method('build')->willReturn($breadcrumb);

    $this->menuLinkManager->method('loadLinksByRoute')->willReturn([]);

    $cacheability = new CacheableMetadata();
    $this->resolver->getActiveTrailIds('main', $cacheability);

    $this->assertEqualsCanonicalizing(
      ['node:42', 'config_pages:global'],
      $cacheability->getCacheTags()
    );
    $this->assertContains('route', $cacheability->getCacheContexts());
  }

  /**
   * @covers ::getActiveTrailIds
   * @covers ::pickShallowest
   * @covers ::buildTrailFromLink
   *
   * Unrouted breadcrumb crumbs (`!$url->isRouted()` — e.g. external
   * URLs surfaced by a custom breadcrumb builder) are skipped without
   * touching the menu link manager; iteration continues to the next
   * deeper crumb.
   */
  public function testSkipsUnroutedBreadcrumbCrumbs(): void {
    $this->menuActiveTrail
      ->method('getActiveTrailIds')
      ->willReturn(['' => '']);

    // Unrouted URL — e.g. an external href surfaced by a custom
    // breadcrumb builder.
    $unrouted_url = $this->getMockBuilder(Url::class)
      ->disableOriginalConstructor()
      ->getMock();
    $unrouted_url->method('isRouted')->willReturn(FALSE);

    // The resolver iterates `array_reverse($breadcrumb->getLinks())`,
    // so the DEEPEST (last-original-position) crumb is inspected first.
    // To exercise the unrouted-skip branch the unrouted crumb must sit
    // AFTER the matching Parent — reversed walk hits unrouted first,
    // skips it, then matches Parent on the next iteration.
    $breadcrumb = new Breadcrumb();
    $breadcrumb->setLinks([
      $this->makeLink('<front>', []),
      Link::fromTextAndUrl('Parent', $this->makeRoutedUrl('entity.node.canonical', ['node' => '10'])),
      new Link('External', $unrouted_url),
    ]);
    $this->breadcrumbBuilder->method('build')->willReturn($breadcrumb);

    // Hard guarantee: the menu link manager must NEVER be asked about
    // the unrouted crumb (the resolver short-circuits before reaching
    // it). Only Parent's route should be looked up.
    $this->menuLinkManager
      ->expects($this->once())
      ->method('loadLinksByRoute')
      ->with('entity.node.canonical', ['node' => '10'], 'main')
      ->willReturn(['menu_link_content:parent' => $this->makeMenuLink('menu_link_content:parent', '')]);

    $result = $this->resolver->getActiveTrailIds('main');

    // The unrouted crumb was skipped; iteration continued to Parent and
    // returned its trail.
    $this->assertSame([
      '' => '',
      'menu_link_content:parent' => 'menu_link_content:parent',
    ], $result);
  }

  /**
   * Helper: create a Link with a routed Url.
   */
  private function makeLink(string $route_name, array $params): Link {
    return Link::fromTextAndUrl('label', Url::fromRoute($route_name, $params));
  }

  /**
   * Helper: build a routed Url for a node route.
   */
  private function makeRoutedUrl(string $route_name, array $params): Url {
    return Url::fromRoute($route_name, $params);
  }

  /**
   * Helper: mock a MenuLinkInterface with plugin ID and parent ID.
   */
  private function makeMenuLink(string $plugin_id, string $parent_id) {
    $link = $this->createMock(MenuLinkInterface::class);
    $link->method('getPluginId')->willReturn($plugin_id);
    $link->method('getParent')->willReturn($parent_id);
    return $link;
  }

}
