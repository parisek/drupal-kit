<?php

namespace Drupal\drupal_kit\Services;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds the menu tree structure consumed by templates.
 *
 * Extracted from EntityHelper to slim it into a facade. Public
 * consumers still call EntityHelper::getMenu(); the
 * facade injects its own formatField() as the optional $field_formatter
 * to keep menu-link-extras enrichment working without recreating the
 * full EntityHelper state inside the builder.
 *
 * Cache metadata accumulated during build() drains via
 * collectCacheMetadata(); EntityHelper bubbles that into its own
 * collector (try/finally so partial-on-exception state never leaks).
 */
class MenuTreeBuilder {

  /**
   * Accumulator for cache metadata collected during build().
   */
  protected CacheableMetadata $cacheMetadata;

  public function __construct(
    protected MenuLinkTreeInterface $menuLinkTree,
    protected MenuActiveTrailResolver $menuActiveTrailResolver,
    protected LanguageManagerInterface $languageManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RequestStack $requestStack,
    // Both are optional, and both come from the same core patch
    // (https://www.drupal.org/project/drupal/issues/2466553), which is
    // NOT in Drupal core — the service exists only where a consumer
    // applied it. Neither present means no filtering, and menu items for
    // all languages appear; Hook\Requirements reports that.
    //
    // Older revisions of the patch ship `menu.language_tree_manipulator`
    // with a filterLanguage() method.
    protected ?object $languageTreeManipulator = NULL,
    // Revision #255, rerolled against 11.4, renamed it to
    // `menu.language_menu_link_tree_manipulator` and reshaped it into a
    // MenuLinkTreeContextualManipulatorInterface. That one is applied
    // through process(), and its own applies() restricts it to
    // SystemMenuBlock, so this builder calls process() directly and
    // passes NULL as the context — process() only hands the context to
    // its own recursion.
    protected ?object $contextualLanguageTreeManipulator = NULL,
  ) {
    $this->cacheMetadata = new CacheableMetadata();
  }

  /**
   * Drain and reset the accumulated cache metadata.
   */
  public function collectCacheMetadata(): CacheableMetadata {
    $metadata = $this->cacheMetadata;
    $this->cacheMetadata = new CacheableMetadata();
    return $metadata;
  }

  /**
   * Build the menu items for a given menu.
   *
   * @param string $menu_name
   *   The menu machine name (e.g. 'main').
   * @param array $params
   *   Build options:
   *   - root: limit the tree to the given root link id.
   * @param callable|null $field_formatter
   *   Optional enrichment callback applied to menu_link_content entities
   *   for every `field_*` field. Called as
   *   $field_formatter($entity, $field_name) and the result is placed
   *   under the field name minus the `field_` prefix. EntityHelper wires
   *   this to its formatField(). When NULL (e.g. direct use of the
   *   builder), the base shape is returned without extras-field
   *   enrichment.
   *
   * @return array
   *   Recursive array of menu items. Each item:
   *   ['id', 'title', 'description', 'url', 'attributes', 'is_active',
   *   'in_active_trail', 'below', plus any *enriched extras*].
   */
  public function build(string $menu_name, array $params = [], ?callable $field_formatter = NULL): array {
    $menu_tree = $this->menuLinkTree;

    $parameters = $menu_tree->getCurrentRouteMenuTreeParameters($menu_name);
    $parameters->setMinDepth(1);

    if (isset($params['root'])) {
      $parameters->setRoot($params['root']);
    }

    // Clear expanded parents array to always display a dropdown.
    $parameters->expandedParents = [];

    // Override the active trail: Drupal's native MenuActiveTrail returns
    // an empty trail for routes with no menu link (e.g. deep accessory
    // pages). The resolver walks the breadcrumb to find the deepest
    // ancestor that IS in the menu and uses that as the active link, so
    // only one top-level item lights up. Breadcrumb cacheability bubbles
    // into our accumulator via the resolver's second argument.
    $parameters->setActiveTrail(
      $this->menuActiveTrailResolver->getActiveTrailIds($menu_name, $this->cacheMetadata),
    );

    $tree = $menu_tree->load($menu_name, $parameters);

    $manipulators = [
      ['callable' => 'menu.default_tree_manipulators:checkAccess'],
      ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
    ];
    // Filter by the current content language. Prefer the contextual
    // manipulator from patch revision #255; fall back to the older
    // revision's service. Neither present means no filtering.
    $language_manipulator = NULL;
    if ($this->contextualLanguageTreeManipulator !== NULL) {
      $manipulators[] = [
        'callable' => 'menu.language_menu_link_tree_manipulator:process',
        'args' => [NULL],
      ];
      $language_manipulator = $this->contextualLanguageTreeManipulator;
    }
    elseif ($this->languageTreeManipulator !== NULL) {
      $manipulators[] = ['callable' => 'menu.language_tree_manipulator:filterLanguage'];
      $language_manipulator = $this->languageTreeManipulator;
    }

    // Calling process() as a plain callable bypasses transform()'s
    // contextual-manipulator loop, which is what would otherwise attach
    // the manipulator's own cacheability (languages:language_content).
    // Attach it here, or a menu filtered per content language is cached
    // without varying by it.
    if ($language_manipulator instanceof CacheableDependencyInterface) {
      $this->cacheMetadata->addCacheableDependency($language_manipulator);
    }

    // Two arguments on purpose. transform() deprecates a NULL $context
    // in 11.2 and drops it in 12.0, but MenuLinkTreeInterface still has
    // the parameter commented out, so passing it violates the interface
    // this property is typed against. Core has to declare it first.
    $tree = $menu_tree->transform($tree, $manipulators);
    $menu = $menu_tree->build($tree);

    // Bubble cache metadata from the built menu render array (access
    // contexts + route-bound entity tags) into the collector.
    $this->cacheMetadata->addCacheableDependency(
      CacheableMetadata::createFromRenderArray($menu),
    );

    $items = [];
    if (isset($menu['#items']) && !empty($menu['#items'])) {
      $items = $this->renderLinks($menu['#items'], $field_formatter);
    }

    return $items;
  }

  /**
   * Recursively transform Drupal's menu-tree items into our flat shape.
   */
  protected function renderLinks(array $items, ?callable $field_formatter): array {
    $langcode = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
    $storage = $this->entityTypeManager->getStorage('menu_link_content');
    $current_path = $this->requestStack->getCurrentRequest()?->getRequestUri() ?? '';

    $links = [];
    foreach ($items as $key => $item) {
      // Honor content_translation_status: skip translation-disabled menu
      // items (isPublished() is not reliable for menu_link_content).
      $entity = NULL;
      if (!empty($item['original_link']->getPluginDefinition()['metadata']['entity_id'])) {
        $entity_id = $item['original_link']->getPluginDefinition()['metadata']['entity_id'];
        $entity = $storage->load($entity_id);
        if ($entity && $entity->hasTranslation($langcode)) {
          $entity = $entity->getTranslation($langcode);
          if (isset($entity->content_translation_status)) {
            $translation_status = (bool) $entity->content_translation_status->value;
            if ($translation_status === FALSE) {
              continue;
            }
          }
        }
      }

      $attributes = [];
      if ($item['url']->getOption('attributes')) {
        $attributes = $item['url']->getOption('attributes');
      }
      // Convert any array attributes to space-separated strings.
      foreach ($attributes as $attr_key => $value) {
        if (is_array($value)) {
          $attributes[$attr_key] = implode(' ', $value);
        }
      }

      $is_active = ($item['url']->toString() == $current_path);

      // Drupal's MenuLinkTree sets in_active_trail on each item based on
      // the trail we injected via MenuTreeParameters::setActiveTrail().
      $in_active_trail = !empty($item['in_active_trail']);

      $below = [];
      if ($item['below']) {
        $below = $this->renderLinks($item['below'], $field_formatter);
      }

      $link_data = [
        'id' => $key,
        'title' => $item['title'],
        'description' => $entity ? $entity->getDescription() : '',
        'url' => $item['url']->toString(),
        'attributes' => $attributes,
        'is_active' => $is_active,
        'in_active_trail' => $in_active_trail,
        'below' => $below,
      ];

      // Optional Menu Item Extras enrichment via the caller's formatter.
      if ($entity && $field_formatter !== NULL) {
        $field_definitions = $entity->getFieldDefinitions();
        foreach ($field_definitions as $field_name => $field_definition) {
          if (strpos($field_name, 'field_') === 0
            && $entity->hasField($field_name)
            && !$entity->get($field_name)->isEmpty()) {
            $extras_key = substr($field_name, 6);
            $link_data[$extras_key] = $field_formatter($entity, $field_name);
          }
        }
      }

      $links[] = $link_data;
    }

    return $links;
  }

}
