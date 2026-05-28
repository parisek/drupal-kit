<?php

namespace Drupal\custom_components\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;
use Drupal\Core\Routing\RoutingEvents;

/**
 * Listens to the dynamic route events.
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  public function alterRoutes(RouteCollection $collection) {
    if ($route = $collection->get('entity.feeds_feed.canonical')) {
      $route->setOption('_admin_route', TRUE);
    }
    if ($route = $collection->get('feeds.item_list')) {
      $route->setOption('_admin_route', TRUE);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events = parent::getSubscribedEvents();

    // Come after views.
    $events[RoutingEvents::ALTER] = ['onAlterRoutes', -180];

    return $events;
  }

}
