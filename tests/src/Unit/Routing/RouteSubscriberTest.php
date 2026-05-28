<?php

namespace Drupal\Tests\custom_components\Unit\Routing;

use Drupal\Core\Routing\RoutingEvents;
use Drupal\custom_components\Routing\RouteSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * @coversDefaultClass \Drupal\custom_components\Routing\RouteSubscriber
 * @group custom_components
 */
class RouteSubscriberTest extends TestCase {

  /**
   * @covers ::alterRoutes
   *
   * The taxonomy_term canonical controller override was removed in
   * #65 — Drupal core's default route already does what the override
   * did (renders the term in `full` view mode via `_entity_view`).
   * Assert the alter is now a no-op for that route so we don't
   * regress.
   */
  public function testTaxonomyTermCanonicalRouteIsLeftUntouched(): void {
    $collection = new RouteCollection();
    $route = new Route('/taxonomy/term/{taxonomy_term}');
    $route->setDefault('_entity_view', 'taxonomy_term.full');
    $collection->add('entity.taxonomy_term.canonical', $route);

    (new RouteSubscriber())->alterRoutes($collection);

    $altered = $collection->get('entity.taxonomy_term.canonical');
    // Core default preserved; no _controller injected on top.
    $this->assertSame('taxonomy_term.full', $altered->getDefault('_entity_view'));
    $this->assertNull($altered->getDefault('_controller'));
  }

  /**
   * @covers ::alterRoutes
   */
  public function testFeedsRoutesAreMarkedAsAdminRoutes(): void {
    $collection = new RouteCollection();
    $collection->add('entity.feeds_feed.canonical', new Route('/feed/{feeds_feed}'));
    $collection->add('feeds.item_list', new Route('/feed/{feeds_feed}/list'));

    (new RouteSubscriber())->alterRoutes($collection);

    $this->assertTrue(
      $collection->get('entity.feeds_feed.canonical')->getOption('_admin_route'),
    );
    $this->assertTrue(
      $collection->get('feeds.item_list')->getOption('_admin_route'),
    );
  }

  /**
   * @covers ::alterRoutes
   */
  public function testMissingRoutesAreNotCreated(): void {
    $collection = new RouteCollection();

    (new RouteSubscriber())->alterRoutes($collection);

    // No route should have been added; alterRoutes is a no-op when none
    // of the watched routes exist.
    $this->assertCount(0, $collection);
  }

  /**
   * @covers ::getSubscribedEvents
   */
  public function testRunsAfterViewsOnRoutingAlterEvent(): void {
    $events = RouteSubscriber::getSubscribedEvents();

    $this->assertArrayHasKey(RoutingEvents::ALTER, $events);
    // Format: ['onAlterRoutes', priority]. Lower priority runs later;
    // views runs at -175, this needs to run after, so -180 is correct.
    $this->assertSame(['onAlterRoutes', -180], $events[RoutingEvents::ALTER]);
  }

}
