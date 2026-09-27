<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Kernel\FormElement;

use Drupal\Core\TypedData\ComplexDataInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\drupal_kit\FormElement\MenuSelect;
use Drupal\language\Config\LanguageConfigOverride;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\system\Entity\Menu;

/**
 * Tests the form element behind the menu locations "Translate" tab.
 *
 * @coversDefaultClass \Drupal\drupal_kit\FormElement\MenuSelect
 * @group drupal_kit
 */
class MenuSelectKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['drupal_kit', 'system', 'language', 'locale', 'config_translation'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    ConfigurableLanguage::createFromLangcode('en')->save();
    ConfigurableLanguage::createFromLangcode('de')->save();
    Menu::create(['id' => 'main', 'label' => 'Main'])->save();
    Menu::create(['id' => 'main_de', 'label' => 'Main DE'])->save();
  }

  /**
   * The translation form element is a select listing every menu.
   *
   * @covers ::getTranslationElement
   */
  public function testGetTranslationElementRendersSelectOfMenus(): void {
    $element = $this->buildElement();
    $german = $this->container->get('language_manager')->getLanguage('de');
    $this->assertNotNull($german, 'The de language exists — created in setUp().');

    $build = $element->getTranslationElement($german, 'main', 'main');

    $this->assertSame('select', $build['#type']);
    $this->assertArrayHasKey('', $build['#options']);
    $this->assertSame('Main', (string) $build['#options']['main']);
    $this->assertSame('Main DE', (string) $build['#options']['main_de']);
  }

  /**
   * Submitting a different menu writes a language config override.
   *
   * This is what config_translation's own "Translate" tab does on submit —
   * MenuSelect uses FormElementBase's inherited setConfig() unmodified, so
   * this exercises the real save path, not a stand-in for it.
   *
   * @covers ::getTranslationElement
   */
  public function testSetConfigSavesTheOverrideWhenDifferentFromSource(): void {
    $this->config('drupal_kit.menu_locations')
      ->set('locations.stark.header_menu', 'main')
      ->save();

    $element = $this->buildElement();
    $base_config = $this->config('drupal_kit.menu_locations');
    $override = $this->getOverride('de');

    $element->setConfig($base_config, $override, 'main_de', 'locations.stark.header_menu');
    $override->save();

    $saved = $this->container->get('language.config_factory_override')
      ->getOverride('de', 'drupal_kit.menu_locations')
      ->get('locations.stark.header_menu');
    $this->assertSame('main_de', $saved);
  }

  /**
   * Submitting the SAME value as the source clears any existing override.
   *
   * FormElementBase::setConfig()'s own contract — an unchanged translation
   * is not a translation, and MenuSelect does not override this behavior.
   *
   * @covers ::getTranslationElement
   */
  public function testSetConfigClearsAnOverrideEqualToTheSource(): void {
    $this->config('drupal_kit.menu_locations')
      ->set('locations.stark.header_menu', 'main')
      ->save();
    $this->getOverride('de')->set('locations.stark.header_menu', 'main_de')->save();

    $element = $this->buildElement();
    $base_config = $this->config('drupal_kit.menu_locations');
    $override = $this->getOverride('de');
    // Submitting 'main' again — the same as the source — must clear the
    // override rather than store a redundant explicit copy of it.
    $element->setConfig($base_config, $override, 'main', 'locations.stark.header_menu');

    $this->assertNull($override->get('locations.stark.header_menu'));
  }

  /**
   * The "Translate" tab route exists once config_translation is installed.
   *
   * @covers \Drupal\drupal_kit\Services\MenuLocations
   */
  public function testTranslateTabRouteExists(): void {
    $this->container->get('router.builder')->rebuild();
    $route = $this->container->get('router.route_provider')
      ->getRouteByName('config_translation.item.overview.drupal_kit.menu_locations');

    $this->assertNotNull($route);
    $this->assertStringEndsWith(
      '/translate',
      $route->getPath(),
      'The overview route config_translation registers for this mapper is the Translate tab itself.',
    );
  }

  /**
   * Builds a MenuSelect element for the header_menu slot's own leaf schema.
   */
  protected function buildElement(): MenuSelect {
    $schema = $this->container->get('config.typed')->createFromNameAndData(
      'drupal_kit.menu_locations',
      ['locations' => ['stark' => ['header_menu' => 'main']]],
    );
    // TypedConfigManagerInterface::createFromNameAndData() is typed to the
    // broad TraversableTypedDataInterface; a `config_object` schema root is
    // concretely a \Drupal\Core\Config\Schema\ConfigObject, which — like
    // every `mapping`/`sequence` step below it — implements the narrower
    // ComplexDataInterface that actually declares get(). Each step is
    // asserted, not cast, so a schema shape that stopped matching this
    // chain would fail loudly here instead of a silent type mismatch.
    $this->assertInstanceOf(ComplexDataInterface::class, $schema);
    $locations = $schema->get('locations');
    $this->assertInstanceOf(ComplexDataInterface::class, $locations);
    $theme = $locations->get('stark');
    $this->assertInstanceOf(ComplexDataInterface::class, $theme);
    $leaf = $theme->get('header_menu');

    return MenuSelect::create($leaf);
  }

  /**
   * Fetches the German-language config override object.
   */
  protected function getOverride(string $langcode): LanguageConfigOverride {
    $override = $this->container->get('language.config_factory_override')
      ->getOverride($langcode, 'drupal_kit.menu_locations');
    // language.config_factory_override's own interface declares this
    // return type as the broader \Drupal\Core\Config\Config — its
    // concrete implementation always returns a LanguageConfigOverride,
    // which is what setConfig() actually requires.
    $this->assertInstanceOf(LanguageConfigOverride::class, $override);

    return $override;
  }

}
