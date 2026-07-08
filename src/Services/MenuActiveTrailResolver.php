<?php

namespace Drupal\drupal_kit\Services;

use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Menu\MenuActiveTrailInterface;
use Drupal\Core\Menu\MenuLinkInterface;
use Drupal\Core\Menu\MenuLinkManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Resolves the active trail for a menu, with breadcrumb fallback.
 *
 * Drupal's MenuActiveTrail returns an empty trail when the current route
 * has no matching menu link (typical for deep content pages like accessory
 * details). This service extends that behavior by walking the breadcrumb
 * backward to find the deepest ancestor that IS in the menu, and using
 * that as the active link.
 *
 * The returned array is compatible with MenuTreeParameters::setActiveTrail()
 * so the rest of menu rendering (including Drupal's built-in in_active_trail
 * flag on each tree item) works without further customization.
 *
 * Wired against Drupal's @breadcrumb chain manager: it dispatches to every
 * registered breadcrumb builder in priority order, so custom builders
 * (e.g. custom_breadcrumb) contribute automatically when enabled, and the
 * core default builder is used otherwise. No hard module dependency.
 */
class MenuActiveTrailResolver {

  public function __construct(
    private readonly MenuActiveTrailInterface $menuActiveTrail,
    private readonly MenuLinkManagerInterface $menuLinkManager,
    private readonly BreadcrumbBuilderInterface $breadcrumbBuilder,
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * Returns active trail IDs for the given menu.
   *
   * @param string $menu_name
   *   The menu machine name.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheability
   *   Optional bag that collects cache metadata from breadcrumb access
   *   (so callers can bubble it into the menu render array).
   *
   * @return array<string, string>
   *   Plugin-ID-keyed array (id => id) plus '' => '' root entry. Compatible
   *   with MenuTreeParameters::setActiveTrail().
   */
  public function getActiveTrailIds(string $menu_name, ?CacheableMetadata $cacheability = NULL): array {
    $native = $this->menuActiveTrail->getActiveTrailIds($menu_name);

    // Native trail has menu links beyond the root entry → current route is
    // in the menu, nothing to fall back to.
    if (count($native) > 1) {
      return $native;
    }

    $breadcrumb = $this->breadcrumbBuilder->build($this->routeMatch);
    if ($cacheability) {
      $cacheability->addCacheableDependency($breadcrumb);
    }

    // Walk breadcrumb from deepest to shallowest, return the first match.
    foreach (array_reverse($breadcrumb->getLinks()) as $link) {
      $url = $link->getUrl();
      if (!$url->isRouted()) {
        continue;
      }
      $route_name = $url->getRouteName();
      // Skip placeholder crumbs (e.g. current page) with no real route —
      // loadLinksByRoute('', [], $menu) would wildcard-match every <nolink>
      // dropdown parent and corrupt the trail.
      if ($route_name === '' || $route_name === '<none>' || $route_name === '<nolink>') {
        continue;
      }
      $matches = $this->menuLinkManager->loadLinksByRoute(
        $route_name,
        $url->getRouteParameters(),
        $menu_name,
      );
      if ($matches) {
        return $this->buildTrailFromLink($this->pickShallowest($matches));
      }
    }

    return ['' => ''];
  }

  /**
   * Pick the menu link closest to the menu root.
   *
   * LoadLinksByRoute() can return multiple links pointing at the same route
   * (e.g. a top-level section plus an SEO duplicate buried in another tree).
   * Preferring the shallowest one keeps the active trail aligned with the
   * primary section a breadcrumb crumb refers to.
   *
   * @param array<string, \Drupal\Core\Menu\MenuLinkInterface> $matches
   *   Non-empty list of menu link plugins.
   */
  private function pickShallowest(array $matches): MenuLinkInterface {
    if (count($matches) === 1) {
      return reset($matches);
    }
    // Seed from the first match so $best is provably non-null without an
    // explicit assert/narrowing — the loop simply replaces it if a shallower
    // sibling appears.
    $best = reset($matches);
    $best_depth = count($this->menuLinkManager->getParentIds($best->getPluginId()));
    foreach ($matches as $link) {
      $depth = count($this->menuLinkManager->getParentIds($link->getPluginId()));
      if ($depth < $best_depth) {
        $best = $link;
        $best_depth = $depth;
      }
    }
    return $best;
  }

  /**
   * Walk a menu link's parents to build a full active trail.
   *
   * @return array<string, string>
   *   Plugin-ID-keyed array (id => id) plus '' => '' root entry.
   */
  private function buildTrailFromLink(MenuLinkInterface $link): array {
    $trail = ['' => ''];
    $trail[$link->getPluginId()] = $link->getPluginId();
    $parent_id = $link->getParent();
    while ($parent_id) {
      $trail[$parent_id] = $parent_id;
      $parent = $this->menuLinkManager->createInstance($parent_id);
      // createInstance() is typed as a bare object — stop walking if the
      // plugin is somehow not a menu link rather than fatal on getParent().
      $parent_id = $parent instanceof MenuLinkInterface ? $parent->getParent() : '';
    }
    return $trail;
  }

}
