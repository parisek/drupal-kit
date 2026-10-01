<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\FormElement;

use Drupal\Core\Language\LanguageInterface;
use Drupal\config_translation\FormElement\FormElementBase;

/**
 * Renders a menu SELECT on the "Translate" tab of the menu locations form.
 *
 * The `Textfield` element config_translation ships is what a scalar
 * `translatable` type gets by default — a one-line text box. That is wrong
 * for a menu machine name: a translator picking a menu by hand from a text
 * field has no list to choose from and no protection against a typo, the
 * same reason `MenuLocationsForm` itself uses a `select`, not a textfield,
 * for the default-language assignment. This class is the config_translation
 * counterpart of that same choice, wired in through
 * `config/schema/drupal_kit.schema.yml`'s `form_element_class` key on the
 * `drupal_kit.menu_locations.menu_name` type.
 *
 * Loaded only by config_translation itself
 * (`ConfigTranslationFormBase::createFormElement()`), and only for a
 * project that has that module installed — see
 * `Drupal\drupal_kit\Services\MenuLocations`'s own class docblock for why
 * that has to stay true and how a project without it still works.
 */
class MenuSelect extends FormElementBase {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The select render array for the translation form.
   */
  public function getTranslationElement(LanguageInterface $translation_language, $source_config, $translation_config) {
    $options = ['' => $this->t('- None -')];
    // Static \Drupal:: call, not an injected service — ElementInterface's
    // own create() takes only the schema element, the same constraint core's
    // DateFormat and TextFormat form elements are under (both reach
    // \Drupal::service() from inside getTranslationElement() too).
    $menus = \Drupal::entityTypeManager()->getStorage('menu')->loadMultiple();
    foreach ($menus as $menu_name => $menu) {
      $options[$menu_name] = $menu->label();
    }

    return [
      '#type' => 'select',
      '#options' => $options,
    ] + parent::getTranslationElement($translation_language, $source_config, $translation_config);
  }

}
