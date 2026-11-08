<?php

declare(strict_types=1);

namespace Drupal\drupal_kit_paragraphs\Display;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\State\StateInterface;

/**
 * Switches one paragraph bundle between its legacy display and the component.
 *
 * A bundle renders through one extra field on its view display. This service
 * swaps that extra field between the hand-written one (the legacy display) and
 * `paragraph_component`. It remembers the legacy settings in State, so a switch
 * back restores them. No release and no config import is needed to roll back.
 */
class ParagraphDisplaySwitcher {

  /**
   * The component name of the extra field this module provides.
   */
  public const COMPONENT_FIELD = 'extra_field_paragraph_component';

  /**
   * The legacy extra field of most sites.
   */
  public const DEFAULT_LEGACY_FIELD = 'extra_field_default_paragraph_display';

  /**
   * Constructs a ParagraphDisplaySwitcher.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected StateInterface $state,
  ) {}

  /**
   * Switches a bundle.
   *
   * @param string $bundle
   *   The paragraph bundle.
   * @param string $target
   *   Either `component` or `legacy`.
   * @param string $viewMode
   *   The view mode of the display.
   * @param string $legacyField
   *   The name of the legacy extra field on the display.
   * @param bool $dryRun
   *   When TRUE, nothing is saved.
   *
   * @return array{display: string, target: string, changed: bool, saved: bool, note: string}
   *   What happened.
   *
   * @throws \InvalidArgumentException
   *   When the target is unknown, the display does not exist, or the display
   *   has neither field.
   */
  public function switch(string $bundle, string $target, string $viewMode = 'default', string $legacyField = self::DEFAULT_LEGACY_FIELD, bool $dryRun = FALSE): array {
    if (!in_array($target, ['component', 'legacy'], TRUE)) {
      throw new \InvalidArgumentException(sprintf('The target must be "component" or "legacy", "%s" given.', $target));
    }

    $id = "paragraph.$bundle.$viewMode";
    $display = $this->entityTypeManager->getStorage('entity_view_display')->load($id);
    if (!$display instanceof EntityViewDisplay) {
      throw new \InvalidArgumentException(sprintf('The view display %s does not exist.', $id));
    }

    $key = "drupal_kit_paragraphs.legacy_display.$bundle.$viewMode";
    $remember = NULL;
    $legacy = $display->getComponent($legacyField);
    $component = $display->getComponent(self::COMPONENT_FIELD);

    if ($target === 'component') {
      if ($component !== NULL && $legacy === NULL) {
        return $this->result($id, $target, FALSE, FALSE, 'The display already uses the component.');
      }
      if ($legacy === NULL) {
        throw new \InvalidArgumentException(sprintf('The display %s shows neither %s nor %s.', $id, $legacyField, self::COMPONENT_FIELD));
      }
      // Keep the settings, to restore them on the way back.
      $remember = ['field' => $legacyField, 'config' => $legacy];
      $display->removeComponent($legacyField);
      $display->setComponent(self::COMPONENT_FIELD, [
        'weight' => $legacy['weight'] ?? 0,
        'settings' => [],
        'third_party_settings' => [],
      ]);
    }
    else {
      if ($legacy !== NULL && $component === NULL) {
        return $this->result($id, $target, FALSE, FALSE, 'The display already uses the legacy field.');
      }
      if ($component === NULL) {
        throw new \InvalidArgumentException(sprintf('The display %s shows neither %s nor %s.', $id, self::COMPONENT_FIELD, $legacyField));
      }
      $saved = $this->state->get($key);
      $field = is_array($saved) && is_string($saved['field'] ?? NULL) ? $saved['field'] : $legacyField;
      $config = is_array($saved) && is_array($saved['config'] ?? NULL)
        ? $saved['config']
        : ['weight' => $component['weight'] ?? 0, 'settings' => [], 'third_party_settings' => []];
      $display->removeComponent(self::COMPONENT_FIELD);
      // The component field is hidden by default, so a "hidden" marker for it
      // only adds noise. Dropping it restores the display as it was before the
      // first switch. The legacy field keeps its marker on the way in: its
      // default may be visible.
      $hidden = $display->get('hidden');
      if (is_array($hidden)) {
        unset($hidden[self::COMPONENT_FIELD]);
        $display->set('hidden', $hidden);
      }
      $display->setComponent($field, $config);
    }

    if ($dryRun) {
      return $this->result($id, $target, TRUE, FALSE, 'Dry run. Nothing was saved.');
    }
    // The legacy settings go to State before the save. A failed save leaves
    // the display unchanged, and the State value is then harmless.
    if ($remember !== NULL) {
      $this->state->set($key, $remember);
    }
    $display->save();
    return $this->result($id, $target, TRUE, TRUE, 'The display was saved.');
  }

  /**
   * Builds a result row.
   *
   * @return array{display: string, target: string, changed: bool, saved: bool, note: string}
   *   The row.
   */
  private function result(string $id, string $target, bool $changed, bool $saved, string $note): array {
    return ['display' => $id, 'target' => $target, 'changed' => $changed, 'saved' => $saved, 'note' => $note];
  }

}
