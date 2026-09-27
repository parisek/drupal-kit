<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Assigns a menu to each of the default theme's menu locations, per language.
 *
 * WordPress calls this pattern `register_nav_menus()`: a theme declares
 * named slots, a site builder assigns a menu to each one. This form is the
 * assignment step. The theme reads the result through
 * \Drupal\drupal_kit\Services\MenuLocations::items() from a preprocess
 * function — see docs/menu-locations.md.
 *
 * Always edits the site's default (frontend) theme, not the theme currently
 * rendering the admin UI — a site builder configuring menu locations expects
 * to configure the theme visitors see. Left alone: a theme picker on this
 * form. Every site this shipped for runs one front-end theme; a project with
 * more than one can call MenuLocations with an explicit $theme argument from
 * its own code even though this form does not expose the choice.
 */
final class MenuLocationsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected ThemeExtensionList $themeExtensionList,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LanguageManagerInterface $languageManager,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('extension.list.theme'),
      $container->get('entity_type.manager'),
      $container->get('language_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'drupal_kit_menu_locations_form';
  }

  /**
   * {@inheritdoc}
   *
   * @return string[]
   *   The config object this form edits.
   */
  protected function getEditableConfigNames(): array {
    return ['drupal_kit.menu_locations'];
  }

  /**
   * The theme this form always edits.
   */
  protected function theme(): string {
    return $this->config('system.theme')->get('default');
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The built form render array.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $theme = $this->theme();
    $info = $this->themeExtensionList->getExtensionInfo($theme);
    /** @var array<string, string> $slots */
    $slots = $info['menu_locations'] ?? [];

    if (!$slots) {
      $form['no_slots'] = [
        '#markup' => $this->t(
          'The default theme (%theme) declares no menu locations. Add a %key key to its %file to declare one.',
          [
            '%theme' => $theme,
            '%key' => 'menu_locations',
            '%file' => "$theme.info.yml",
          ],
        ),
      ];
      return parent::buildForm($form, $form_state);
    }

    $menu_options = ['' => $this->t('- None -')];
    $menus = $this->entityTypeManager->getStorage('menu')->loadMultiple();
    foreach ($menus as $menu_name => $menu) {
      $menu_options[$menu_name] = $menu->label();
    }

    $config = $this->config('drupal_kit.menu_locations');
    $languages = $this->languageManager->getLanguages();

    foreach ($slots as $slot => $label) {
      $form[$slot] = [
        '#type' => 'details',
        '#title' => $label,
        '#open' => TRUE,
        '#tree' => TRUE,
      ];
      foreach ($languages as $langcode => $language) {
        $form[$slot][$langcode] = [
          '#type' => 'select',
          '#title' => $language->getName(),
          '#options' => $menu_options,
          '#default_value' => $config->get("locations.$theme.$slot.$langcode") ?? '',
        ];
      }
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   *
   * $form keeps the bare `array` type FormStateInterface's by-ref parameter
   * declares — PHPStan treats a narrower by-ref type as a variance error,
   * so `array<string, mixed>` cannot go here the way it does on buildForm().
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $theme = $this->theme();
    /** @var array<string, string> $slots */
    $slots = $this->themeExtensionList->getExtensionInfo($theme)['menu_locations'] ?? [];

    $config = $this->config('drupal_kit.menu_locations');
    $locations = $config->get('locations') ?? [];
    $theme_assignment = [];
    foreach (array_keys($slots) as $slot) {
      $values = $form_state->getValue((string) $slot) ?? [];
      $slot_assignment = [];
      foreach ($values as $langcode => $menu_name) {
        // Blank ("- None -") is a real choice, not a missing key: a slot
        // that was assigned and is now cleared must not keep resolving to
        // the old menu through a stale array key.
        if ($menu_name !== '') {
          $slot_assignment[$langcode] = $menu_name;
        }
      }
      $theme_assignment[$slot] = $slot_assignment;
    }
    $locations[$theme] = $theme_assignment;
    $config->set('locations', $locations)->save();

    parent::submitForm($form, $form_state);
  }

}
