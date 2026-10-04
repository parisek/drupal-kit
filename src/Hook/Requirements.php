<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\Hook;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\drupal_kit\Services\FeatureFlags;

/**
 * What this module needs from the site, reported on the status page.
 *
 * Implemented as hook_runtime_requirements(), not hook_requirements(). The
 * procedural form without #[LegacyRequirementsHook] is deprecated in
 * drupal:11.3.0 and removed in drupal:13.0.0
 * (HookCollectorPass::collectModuleHook()). The `$phase !== 'runtime'`
 * guard is gone with it: the new hook has no phase, because install-time
 * checks are their own hook.
 *
 * The severity constants matter more. REQUIREMENT_OK and
 * REQUIREMENT_WARNING are deprecated in drupal:11.2.0 and removed in
 * drupal:12.0.0 (includes/install.inc), which makes them the one thing in
 * this module that would have broken on 12.
 *
 * Nothing caught either half, and the reasons are worth writing down.
 * phpstan-deprecation-rules has no rule for global constants, so the
 * REQUIREMENT_* ones were invisible at level 8. And phpunit.xml.dist sets
 * SYMFONY_DEPRECATIONS_HELPER=weak, so the suite counts deprecations and
 * never fails on them — the collector's own trigger_error() is suppressed
 * with @ and did not appear in test output either way, so the deprecation
 * here is read off core's source rather than observed in a run.
 */
class Requirements {

  use StringTranslationTrait;

  /**
   * The optional service is injected, not looked up.
   *
   * This class is registered by hand in drupal_kit.services.yml rather than
   * autowired, which is the one case Hook.php sanctions manual registration
   * for: both manipulator services ship with a core patch and are absent
   * on most sites, and `@?` is how a YAML argument says "NULL when
   * missing". An attribute cannot say that, and autowiring a service that
   * usually does not exist fails the container build.
   *
   * Injecting it also makes the check honest. \Drupal::hasService() asked
   * whether the container knows the name; a nullable argument asks whether
   * this class actually received it, which is the thing MenuTreeBuilder
   * depends on.
   */
  public function __construct(
    protected LanguageManagerInterface $languageManager,
    protected ?object $languageTreeManipulator = NULL,
    protected ?object $contextualLanguageTreeManipulator = NULL,
    protected ?FeatureFlags $featureFlags = NULL,
    protected ?ModuleHandlerInterface $moduleHandler = NULL,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtime(): array {
    return $this->menuLanguageFiltering() + $this->dataLayerPaused();
  }

  /**
   * The menu language filtering row, on a multilingual site.
   *
   * @return array<string, array<string, mixed>>
   *   The requirement, or nothing on a monolingual site.
   */
  protected function menuLanguageFiltering(): array {
    // MenuTreeBuilder filters menu links by the current content language
    // only when the `menu.language_tree_manipulator` service exists — it
    // ships via a Drupal core patch
    // (https://www.drupal.org/project/drupal/issues/2466553) and is wired
    // as an optional constructor dependency. On a multilingual site its
    // absence means menus silently show links in every language, so the
    // gap must be visible on the status report.
    if (!$this->languageManager->isMultilingual()) {
      return [];
    }

    $available = $this->languageTreeManipulator !== NULL
      || $this->contextualLanguageTreeManipulator !== NULL;
    $requirement = [
      'title' => $this->t('Drupal Kit: menu language filtering'),
      'value' => $available ? $this->t('Available') : $this->t('Not available'),
      'severity' => $available ? RequirementSeverity::OK : RequirementSeverity::Warning,
    ];

    if (!$available) {
      $requirement['description'] = $this->t(
        'Neither <code>menu.language_menu_link_tree_manipulator</code> nor <code>menu.language_tree_manipulator</code> exists, so menus built by Drupal Kit are not filtered by the current content language — links from every language will appear. One of the two ships with the Drupal core patch from <a href=":url">issue #2466553</a>, depending on which revision is applied.',
        [':url' => 'https://www.drupal.org/project/drupal/issues/2466553'],
      );
    }

    return ['drupal_kit_language_tree_manipulator' => $requirement];
  }

  /**
   * Says why the dataLayer feature sends nothing, when it is paused.
   *
   * The feature is on and the site-local custom_datalayer module is still
   * installed. Without this row, a project that turned the flag on and sees
   * no push has nowhere to look. Core allows one implementation of a hook per
   * module, so the row lives here, in the module's one runtime_requirements.
   *
   * @return array<string, array<string, mixed>>
   *   The requirement, or nothing when the feature is not paused.
   */
  protected function dataLayerPaused(): array {
    if ($this->featureFlags === NULL || $this->moduleHandler === NULL) {
      return [];
    }
    if (!$this->featureFlags->enabled(FeatureFlags::FLAG_DATALAYER) || !$this->moduleHandler->moduleExists(DataLayerHooks::SITE_LOCAL_MODULE)) {
      return [];
    }

    return [
      'drupal_kit_datalayer' => [
        'title' => $this->t('Drupal Kit: dataLayer'),
        'value' => $this->t('Paused'),
        'severity' => RequirementSeverity::Warning,
        'description' => $this->t('The datalayer feature is on, but the module <code>custom_datalayer</code> is still installed and pushes its own leads. Sending both would report every lead twice, so the feature sends nothing until that module is uninstalled.'),
      ],
    ];
  }

}
