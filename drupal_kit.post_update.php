<?php

/**
 * @file
 * Post update functions for Drupal Kit.
 */

declare(strict_types=1);

/**
 * Installs drupal_kit.menu_locations on sites that enabled the module
 * before this config object existed.
 *
 * config/install only runs on a fresh `drush en drupal_kit` — a site that
 * already had the module enabled when the menu-locations feature landed
 * never gets it, exactly the case ConfigApplier's own `hook_post_update_NAME()`
 * pattern (see README.md) exists for. Uses the module's own `config/install`
 * directory as the fixture source rather than a separate `config/deploy`
 * copy, since the two are identical by construction — one is the source the
 * other would otherwise have to duplicate.
 *
 * Create-only and idempotent: a site where the object already exists (a
 * fresh install, or a site that already ran this update) gets
 * `SKIP-EXISTS` and nothing changes.
 *
 * Only ever installs the empty default (`locations: {}`) — it never wrote
 * an assignment, so the config shape changing from "one menu per slot per
 * language" to "one menu per slot" (this branch, unreleased) needed no
 * migration here.
 */
function drupal_kit_post_update_menu_locations(): void {
  /** @var \Drupal\drupal_kit\Services\ConfigApplier $applier */
  $applier = \Drupal::service('drupal_kit.config_applier');

  $plan = $applier->apply(
    \Drupal::service('extension.list.module')->getPath('drupal_kit') . '/config/install',
    ['drupal_kit.menu_locations'],
  );

  foreach ($plan as $entry) {
    if ($entry['action'] === \Drupal\drupal_kit\Services\ConfigApplier::ACTION_ERROR) {
      throw new \RuntimeException("drupal_kit_post_update_menu_locations: {$entry['name']}: {$entry['reason']}");
    }
  }
}

/**
 * Gives drupal_kit.menu_locations the site's default language code.
 *
 * The object holds the default-language menu per slot, and config_translation
 * reads its source language from `langcode`. Without the key it assumes
 * 'en', so a site whose default language is not English cannot translate the
 * assignment into English. A fresh install ships `langcode: en`, which
 * locale's own rewrite skips: the object has no translatable value until a
 * slot is assigned.
 *
 * Changes only a missing key, or 'en' on a site whose default is not English.
 */
function drupal_kit_post_update_menu_locations_langcode(): string {
  $config = \Drupal::configFactory()->getEditable('drupal_kit.menu_locations');
  if ($config->isNew()) {
    return 'drupal_kit.menu_locations does not exist. Nothing to do.';
  }
  $langcode = $config->get('langcode');
  $default_langcode = \Drupal::service('language.default')->get()->getId();
  if (!empty($langcode) && !($langcode === 'en' && $default_langcode !== 'en')) {
    return "drupal_kit.menu_locations already has langcode '$langcode'.";
  }
  $config->set('langcode', $default_langcode)->save();
  return "Set drupal_kit.menu_locations langcode to '$default_langcode'.";
}
