<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Kernel\Form;

use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Form\FormState;
use Drupal\Core\Language\Language;
use Drupal\KernelTests\KernelTestBase;
use Drupal\drupal_kit\Form\MenuLocationsForm;
use Drupal\system\Entity\Menu;

/**
 * Tests that submitting the menu locations form saves the assignment.
 *
 * The theme's declared slots come from ThemeExtensionList::getExtensionInfo,
 * which reads a real <theme>.info.yml from the filesystem. Rather than
 * shipping a fixture theme just to exercise this, the service is swapped
 * for a stub in the container — the same pattern core kernel tests use for
 * `current_user` and similar — so the test only depends on this module's
 * own code.
 *
 * @coversDefaultClass \Drupal\drupal_kit\Form\MenuLocationsForm
 * @group drupal_kit
 */
class MenuLocationsFormKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['drupal_kit', 'system', 'menu_ui'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);

    $theme_list = $this->createMock(ThemeExtensionList::class);
    $theme_list->method('getExtensionInfo')
      ->willReturn(['menu_locations' => ['header_menu' => 'Header']]);
    $this->container->set('extension.list.theme', $theme_list);

    // menu_ui's own default config already ships a menu called 'main';
    // a distinct id keeps this test independent of that config.
    Menu::create(['id' => 'test_main', 'label' => 'Test main'])->save();
  }

  /**
   * A value pinned in settings.php shows the core "overridden" notice.
   *
   * The form lists the stored value in the select. Core's config binding
   * adds the notice so the site builder knows the stored value is not the
   * one in use.
   *
   * @covers ::buildForm
   */
  public function testOverriddenSlotShowsTheCoreNotice(): void {
    $default_theme = $this->config('system.theme')->get('default');
    $this->config('drupal_kit.menu_locations')
      ->set("locations.$default_theme.header_menu", 'test_main')
      ->save();
    $GLOBALS['config']['drupal_kit.menu_locations']['locations'][$default_theme]['header_menu'] = 'test_other';
    $this->container->get('config.factory')->clearStaticCache();

    $form = \Drupal::formBuilder()->getForm(MenuLocationsForm::class);

    $this->assertArrayHasKey('config_override_status_messages', $form);
    $links = $form['config_override_status_messages']['message']['#message_list']['status'][0]['#links'];
    $this->assertCount(1, $links);
    $this->assertSame('Header', $links[0]['title']);
    $this->assertSame('test_main', $form['header_menu']['#default_value']);
  }

  /**
   * Saving with an override in place stores the form value, not the override.
   *
   * @covers ::submitForm
   */
  public function testSubmitStoresTheFormValueNotTheOverride(): void {
    $default_theme = $this->config('system.theme')->get('default');
    $GLOBALS['config']['drupal_kit.menu_locations']['locations'][$default_theme]['header_menu'] = 'test_other';
    $this->container->get('config.factory')->clearStaticCache();

    $form_state = (new FormState())->setValues(['header_menu' => 'test_main']);
    \Drupal::formBuilder()->submitForm(MenuLocationsForm::class, $form_state);

    $stored = $this->container->get('config.factory')
      ->getEditable('drupal_kit.menu_locations')
      ->get("locations.$default_theme.header_menu");
    $this->assertSame('test_main', $stored);
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitSavesTheAssignmentUnderTheDefaultTheme(): void {
    $default_theme = $this->config('system.theme')->get('default');

    $form_state = (new FormState())->setValues(['header_menu' => 'test_main']);
    \Drupal::formBuilder()->submitForm(MenuLocationsForm::class, $form_state);

    $saved = $this->config('drupal_kit.menu_locations')->get('locations');
    $this->assertSame('test_main', $saved[$default_theme]['header_menu']);
  }

  /**
   * Saving "- None -" clears a previously saved assignment.
   *
   * Choosing "- None -" (an empty string value) clears a previously saved
   * assignment instead of leaving the old menu name in place under a
   * lingering array key.
   *
   * @covers ::submitForm
   */
  public function testSubmitWithNoneMenuClearsPreviousAssignment(): void {
    $default_theme = $this->config('system.theme')->get('default');
    $this->config('drupal_kit.menu_locations')
      ->set("locations.$default_theme.header_menu", 'test_main')
      ->save();

    $form_state = (new FormState())->setValues(['header_menu' => '']);
    \Drupal::formBuilder()->submitForm(MenuLocationsForm::class, $form_state);

    $this->assertNull($this->config('drupal_kit.menu_locations')->get("locations.$default_theme.header_menu"));
    $this->assertArrayNotHasKey('header_menu', $this->config('drupal_kit.menu_locations')->get("locations.$default_theme") ?? []);
  }

  /**
   * Saving stamps the site's default language on the object.
   *
   * The config_translation module reads the source language from
   * `langcode`. A missing key reads as 'en', and English then cannot be a
   * translation.
   *
   * @covers ::submitForm
   */
  public function testSubmitStampsTheDefaultLangcode(): void {
    $this->container->get('language.default')->set(new Language(['id' => 'cs']));

    $form_state = (new FormState())->setValues(['header_menu' => 'test_main']);
    \Drupal::formBuilder()->submitForm(MenuLocationsForm::class, $form_state);

    $this->assertSame('cs', $this->config('drupal_kit.menu_locations')->get('langcode'));
  }

  /**
   * A langcode a site builder already set is not overwritten on save.
   *
   * @covers ::buildForm
   */
  public function testSubmitKeepsAnExistingNonEnglishLangcode(): void {
    $this->container->get('language.default')->set(new Language(['id' => 'cs']));
    $this->config('drupal_kit.menu_locations')->set('langcode', 'de')->save();

    $form_state = (new FormState())->setValues(['header_menu' => 'test_main']);
    \Drupal::formBuilder()->submitForm(MenuLocationsForm::class, $form_state);

    $this->assertSame('de', $this->config('drupal_kit.menu_locations')->get('langcode'));
  }

  /**
   * A slot named "langcode" keeps its select.
   *
   * @covers ::buildForm
   */
  public function testSlotNamedLangcodeKeepsItsSelect(): void {
    $theme_list = $this->createMock(ThemeExtensionList::class);
    $theme_list->method('getExtensionInfo')
      ->willReturn(['menu_locations' => ['langcode' => 'Language menu']]);
    $this->container->set('extension.list.theme', $theme_list);

    $form = \Drupal::formBuilder()->getForm(MenuLocationsForm::class);

    $this->assertSame('select', $form['langcode']['#type']);
  }

  /**
   * A theme without slots gets a message and no Save button.
   *
   * @covers ::buildForm
   */
  public function testThemeWithoutSlotsHasNoSaveButton(): void {
    $theme_list = $this->createMock(ThemeExtensionList::class);
    $theme_list->method('getExtensionInfo')->willReturn([]);
    $this->container->set('extension.list.theme', $theme_list);

    $form = \Drupal::formBuilder()->getForm(MenuLocationsForm::class);

    $this->assertArrayHasKey('no_slots', $form);
    $this->assertArrayNotHasKey('actions', $form);
  }

}
