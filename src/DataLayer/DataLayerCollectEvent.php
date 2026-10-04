<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\DataLayer;

use Drupal\Component\EventDispatcher\Event;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Lets a subscriber add its own pushes and page context to a page.
 *
 * Whatever a subscriber adds depends on something: a route, a session, a
 * node. It says so through cacheability(), because the page it lands on is
 * cached by Drupal. A subscriber that reads the session and does not say so
 * gets a push on the first request and silence on the next.
 */
final class DataLayerCollectEvent extends Event {

  /**
   * The pushes, in the order they were added.
   *
   * @var array<int, array<string, mixed>>
   */
  private array $items = [];

  /**
   * The page context.
   *
   * @var array<string, mixed>
   */
  private array $pageContext = [];

  private readonly CacheableMetadata $cacheability;

  public function __construct(private readonly RouteMatchInterface $routeMatch) {
    $this->cacheability = new CacheableMetadata();
  }

  /**
   * Adds a push.
   *
   * @param array<string, mixed> $item
   *   The object to push onto window.dataLayer.
   */
  public function add(array $item): static {
    $this->items[] = $item;
    return $this;
  }

  /**
   * Adds keys to the page context scripts can read.
   *
   * @param array<string, mixed> $context
   *   Keys merged over what is there; a later call wins.
   */
  public function addPageContext(array $context): static {
    $this->pageContext = array_merge($this->pageContext, $context);
    return $this;
  }

  /**
   * The pushes added so far.
   *
   * @return array<int, array<string, mixed>>
   *   The pushes.
   */
  public function items(): array {
    return $this->items;
  }

  /**
   * The page context added so far.
   *
   * @return array<string, mixed>
   *   The context.
   */
  public function pageContext(): array {
    return $this->pageContext;
  }

  /**
   * The route this page is built for.
   */
  public function routeMatch(): RouteMatchInterface {
    return $this->routeMatch;
  }

  /**
   * What the added pushes depend on. Add to it.
   */
  public function cacheability(): CacheableMetadata {
    return $this->cacheability;
  }

}
