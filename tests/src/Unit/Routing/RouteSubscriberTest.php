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
   */
  public function testTaxonomyTermCanonicalControllerIsOverridden(): void {
    $collection = new RouteCollection();
    $collection->add('entity.taxonomy_term.canonical', new Route('/taxonomy/term/{taxonomy_term}'));

    (new RouteSubscriber())->alterRoutes($collection);

    $this->assertSame(
      '\Drupal\custom_components\Controller\TaxonomyTermController::view',
      $collection->get('entity.taxonomy_term.canonical')->getDefault('_controller'),
    );
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
