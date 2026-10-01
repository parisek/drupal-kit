<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\ToConfig;
use Drupal\Core\Language\LanguageDefault;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Assigns a menu to each of the default theme's menu locations.
 *
 * WordPress calls this pattern `register_nav_menus()`: a theme declares
 * named slots, a site builder assigns a menu to each one. This form is the
 * assignment step. The theme reads the result through
 * \Drupal\drupal_kit\Services\MenuLocations::items() from a preprocess
 * function — see the README's Menu locations section.
 *
 * One select per slot, no per-language fieldsets — see MenuLocations'
 * own class docblock for why a slot takes exactly one menu.
 * `menu_link_content`'s content translation is what varies a slot's
 * items by language, not this form.
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
    protected LanguageDefault $languageDefault,
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
      $container->get('language.default'),
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
      // No Save button: with no #config_target element core's config binding
      // has nothing to save, so a button would do nothing.
      return $form;
    }

    $menu_options = ['' => $this->t('- None -')];
    $menus = $this->entityTypeManager->getStorage('menu')->loadMultiple();
    foreach ($menus as $menu_name => $menu) {
      $menu_options[$menu_name] = $menu->label();
    }

    foreach ($slots as $slot => $label) {
      $form[$slot] = [
        '#type' => 'select',
        '#title' => $label,
        '#options' => $menu_options,
        '#config_target' => new ConfigTarget(
          'drupal_kit.menu_locations',
          "locations.$theme.$slot",
          fromConfig: static fn(?string $menu_name): string => $menu_name ?? '',
          // Blank ("- None -") is a real choice, not a missing key: a slot
          // that was assigned and is now cleared must not keep resolving to
          // the old menu. NULL would be stored as NULL, not removed.
          toConfig: static fn(string $menu_name): string|ToConfig => $menu_name === '' ? ToConfig::DeleteKey : $menu_name,
        ),
      ];
    }

    // This form edits the default-language values, so the object must say
    // so. config_translation treats a missing langcode as 'en', and then
    // refuses to translate into English on a site whose default is not.
    // Slot names come from the theme and sit at the same level of $form, so
    // the key steps aside for a slot that already uses it.
    $langcode_key = 'drupal_kit_langcode';
    while (isset($slots[$langcode_key])) {
      $langcode_key .= '_';
    }
    $form[$langcode_key] = [
      '#type' => 'value',
      // Core's override notice reads a title from every overridden target.
      '#title' => $this->t('Language'),
      '#config_target' => new ConfigTarget(
        'drupal_kit.menu_locations',
        'langcode',
        toConfig: function (): string|ToConfig {
          $langcode = $this->config('drupal_kit.menu_locations')->get('langcode');
          $default_langcode = $this->languageDefault->get()->getId();
          // A stamp already set by a site builder stays, unless it is the
          // implicit 'en' on a site whose default language is not English.
          return empty($langcode) || ($langcode === 'en' && $default_langcode !== 'en')
            ? $default_langcode
            : ToConfig::NoOp;
        },
      ),
    ];

    return parent::buildForm($form, $form_state);
  }

}
