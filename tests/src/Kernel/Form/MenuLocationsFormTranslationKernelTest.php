<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Kernel\Form;

use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Form\FormState;
use Drupal\Core\Language\Language;
use Drupal\KernelTests\KernelTestBase;
use Drupal\drupal_kit\Form\MenuLocationsForm;

/**
 * Tests that the saved config stays translatable with config_translation.
 *
 * The Translate tab reads the source language from the config object's
 * `langcode`. The form stamps it on save, so the default language of the
 * site must not be offered as a translation target.
 *
 * @group drupal_kit
 */
class MenuLocationsFormTranslationKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'drupal_kit',
    'system',
    'menu_ui',
    'language',
    'locale',
    'config_translation',
  ];

  /**
   * The mapper reports the site default language as the source language.
   */
  public function testMapperSourceLanguageIsTheStampedDefault(): void {
    $this->installConfig(['system', 'language']);
    $theme_list = $this->createMock(ThemeExtensionList::class);
    $theme_list->method('getExtensionInfo')
      ->willReturn(['menu_locations' => ['header_menu' => 'Header']]);
    $this->container->set('extension.list.theme', $theme_list);
    $this->container->get('language.default')->set(new Language(['id' => 'cs']));

    $form_state = (new FormState())->setValues(['header_menu' => 'main']);
    \Drupal::formBuilder()->submitForm(MenuLocationsForm::class, $form_state);

    /** @var \Drupal\config_translation\ConfigNamesMapper $mapper */
    $mapper = $this->container->get('plugin.manager.config_translation.mapper')
      ->createInstance('drupal_kit.menu_locations');
    $this->assertSame('cs', $mapper->getLangcode());
  }

}
