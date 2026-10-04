<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Unit\DataLayer;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\drupal_kit\DataLayer\DataLayerCollectEvent;
use PHPUnit\Framework\TestCase;

/**
 * Tests the event a project subscribes to for its own page-load pushes.
 *
 * @coversDefaultClass \Drupal\drupal_kit\DataLayer\DataLayerCollectEvent
 * @group drupal_kit
 */
class DataLayerCollectEventTest extends TestCase {

  /**
   * A subscriber adds pushes in order and the page context merges.
   */
  public function testAddCollectsItemsAndPageContext(): void {
    $event = new DataLayerCollectEvent($this->createMock(RouteMatchInterface::class));

    $event->add(['event' => 'view_item'])->add(['statusCode' => 404]);
    $event->addPageContext(['type' => 'product'])->addPageContext(['price' => 10]);

    $this->assertSame([['event' => 'view_item'], ['statusCode' => 404]], $event->items());
    $this->assertSame(['type' => 'product', 'price' => 10], $event->pageContext());
  }

  /**
   * The event hands the route match to the subscriber.
   */
  public function testRouteMatchIsAvailable(): void {
    $route_match = $this->createMock(RouteMatchInterface::class);

    $this->assertSame($route_match, (new DataLayerCollectEvent($route_match))->routeMatch());
  }

}
