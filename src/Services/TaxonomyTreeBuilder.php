<?php

namespace Drupal\custom_components\Services;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;

/**
 * Builds flat / nested taxonomy term arrays for consumption by templates.
 *
 * Extracted from EntityHelper as part of #6 to slim EntityHelper into a
 * facade. Public consumers continue to call
 * EntityHelper::getTaxonomy($vocabulary, $params); EntityHelper delegates
 * here and bubbles the cache metadata back into its own accumulator.
 */
class TaxonomyTreeBuilder {

  /**
   * Accumulator for cache metadata collected during build().
   *
   * Callers (EntityHelper, or future direct callers) drain this via
   * collectCacheMetadata() and merge it into their own response cache
   * context. Initialized at declaration so a subclass that overrides
   * the constructor without calling parent::__construct() still has a
   * usable collector — relies on PHP 8.3+ `new` in initializers.
   */
  protected CacheableMetadata $cacheMetadata;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LanguageManagerInterface $languageManager,
  ) {
    // Property cannot use `new in initializer` because PHPStan/IDE
    // treat $this access in property initializers as ambiguous; keep
    // the assignment here. The collectCacheMetadata() drain replaces
    // the instance, so callers see a fresh accumulator on each cycle.
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
   * Build the term list for a vocabulary.
   *
   * @param string $vocabulary
   *   The vocabulary machine name.
   * @param array $params
   *   Build options:
   *   - nested: TRUE to return a hierarchical tree with 'children' keys.
   *   - disable_translation: TRUE to skip the per-language translation
   *     existence check (useful for vocabularies that aren't translation
   *     enabled).
   *
   * @return array
   *   List of term items. Each item: ['id' => int, 'title' => string,
   *   'url' => string]. When nested, each item also has 'children' (an
   *   array of the same shape, possibly empty).
   */
  public function build(string $vocabulary, array $params = []): array {
    $items = [];
    $nested = !empty($params['nested']);

    $langcode = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');

    $terms = $term_storage->loadTree($vocabulary, 0, NULL, TRUE);

    $term_map = [];
    $children_map = [];

    foreach ($terms as $term) {
      if (!$term->isPublished()) {
        continue;
      }

      $term_data = NULL;
      $tid = (int) $term->id();

      if (isset($params['disable_translation']) && $params['disable_translation'] === TRUE) {
        $term_data = [
          'id' => $tid,
          'title' => $term->label(),
          'url' => $term->toUrl()->toString(),
        ];
      }
      else {
        if ($term->hasTranslation($langcode)) {
          $translated_term = $term->getTranslation($langcode);
          if (!$translated_term->isPublished()) {
            continue;
          }
          $term_data = [
            'id' => $tid,
            'title' => $translated_term->label(),
            'url' => $translated_term->toUrl()->toString(),
          ];
        }
      }

      if ($term_data) {
        if ($nested) {
          $parents = $term->get('parent')->getValue();
          $parent_id = !empty($parents[0]['target_id']) ? (int) $parents[0]['target_id'] : 0;
          $term_map[$tid] = $term_data;
          if (!isset($children_map[$parent_id])) {
            $children_map[$parent_id] = [];
          }
          $children_map[$parent_id][] = $tid;
        }
        else {
          $items[] = $term_data;
        }
      }

      $this->cacheMetadata->addCacheableDependency($term);
    }

    if ($nested) {
      $items = $this->buildTermTree($term_map, $children_map, 0);
    }

    // Vocabulary list cache tag so new/deleted terms invalidate consumers.
    $this->cacheMetadata->addCacheTags([
      $term_storage->getEntityTypeId() . '_list:' . $vocabulary,
    ]);

    return $items;
  }

  /**
   * Recursively assemble a nested tree from the flat term/children maps.
   */
  protected function buildTermTree(array $term_map, array $children_map, int $parent_id): array {
    $tree = [];
    if (!isset($children_map[$parent_id])) {
      return $tree;
    }
    foreach ($children_map[$parent_id] as $tid) {
      if (!isset($term_map[$tid])) {
        continue;
      }
      $item = $term_map[$tid];
      $item['children'] = $this->buildTermTree($term_map, $children_map, $tid);
      $tree[] = $item;
    }
    return $tree;
  }

}
