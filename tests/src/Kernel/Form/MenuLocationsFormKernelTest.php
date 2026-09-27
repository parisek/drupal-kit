<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Kernel\Form;

use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Form\FormState;
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

    $saved = $this->config('drupal_kit.menu_locations')->get('locations');
    $this->assertArrayNotHasKey('header_menu', $saved[$default_theme]);
  }

}
