<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Unit\Paragraphs;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\FileCache\FileCacheFactory;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Theme\ActiveTheme;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\drupal_kit\Services\EntityHelper;
use Drupal\drupal_kit_paragraphs\Mapper\ParagraphMapperManager;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/fixtures/stubs/ParagraphInterface.php';

/**
 * Tests for plugin discovery and ordering, with theme plugins.
 *
 * The fixtures in tests/fixtures/paragraph_mappers play three installed
 * themes: fixture_theme (active), fixture_base (its base theme) and
 * other_theme (installed, not in the chain). Discovery runs through the real
 * DefaultPluginManager machinery on their PSR-4 directories, the same way core
 * runs it on `container.namespaces`.
 *
 * @coversDefaultClass \Drupal\drupal_kit_paragraphs\Mapper\ParagraphMapperManager
 * @group drupal_kit
 */
class ParagraphMapperManagerTest extends TestCase {

  /**
   * The fixture root.
   */
  private string $fixtures;

  /**
   * The registered autoloader.
   *
   * @var callable
   */
  private $autoloader;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Plugin discovery reads classes through the file cache.
    FileCacheFactory::setConfiguration([FileCacheFactory::DISABLE_CACHE => TRUE]);
    FileCacheFactory::setPrefix('drupal_kit_test');
    $this->fixtures = dirname(__DIR__, 3) . '/fixtures/paragraph_mappers';

    $prefixes = [
      'Drupal\\fixture_theme\\' => $this->fixtures . '/fixture_theme/src/',
      'Drupal\\fixture_base\\' => $this->fixtures . '/fixture_base/src/',
      'Drupal\\other_theme\\' => $this->fixtures . '/other_theme/src/',
    ];
    $this->autoloader = static function (string $class) use ($prefixes): void {
      foreach ($prefixes as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
          $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
          if (is_file($file)) {
            require_once $file;
          }
        }
      }
    };
    spl_autoload_register($this->autoloader);

    $container = new ContainerBuilder();
    $container->set('drupal_kit.entity_helper', $this->createMock(EntityHelper::class));
    \Drupal::setContainer($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    spl_autoload_unregister($this->autoloader);
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * Builds the manager over the fixture namespaces.
   */
  private function manager(string $active = 'fixture_theme', string $default = 'other_theme'): ParagraphMapperManager {
    $namespaces = new \ArrayObject([
      'Drupal\\drupal_kit_paragraphs' => dirname(__DIR__, 4) . '/modules/drupal_kit_paragraphs/src',
      'Drupal\\fixture_theme' => $this->fixtures . '/fixture_theme/src',
      'Drupal\\fixture_base' => $this->fixtures . '/fixture_base/src',
      'Drupal\\other_theme' => $this->fixtures . '/other_theme/src',
    ]);

    $installed = ['fixture_theme', 'fixture_base', 'other_theme'];
    $theme_handler = $this->createMock(ThemeHandlerInterface::class);
    $theme_handler->method('themeExists')->willReturnCallback(static fn ($name) => in_array($name, $installed, TRUE));
    $theme_handler->method('getDefault')->willReturn($default);
    $theme_handler->method('getTheme')->willReturnCallback(function ($name) {
      $extension = $this->createMock(Extension::class);
      // Core sets base_themes as a dynamic property.
      $property = 'base_themes';
      // @phpstan-ignore property.notFound
      $extension->{$property} = $name === 'fixture_theme' ? ['fixture_base' => 'Fixture base'] : [];
      return $extension;
    });

    $active_theme = $this->createMock(ActiveTheme::class);
    $active_theme->method('getName')->willReturn($active);
    $theme_manager = $this->createMock(ThemeManagerInterface::class);
    $theme_manager->method('getActiveTheme')->willReturn($active_theme);

    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('moduleExists')->willReturn(FALSE);

    return new ParagraphMapperManager($namespaces, new MemoryBackend($this->createMock(TimeInterface::class)), $module_handler, $theme_handler, $theme_manager);
  }

  /**
   * Discovery finds attribute plugins in theme namespaces.
   *
   * This is the proof that no extra registration is needed: the theme
   * plugin has provider `fixture_theme`, and providerExists() accepts it.
   *
   * @covers ::providerExists
   */
  public function testDiscoversThemePlugins(): void {
    $definitions = $this->manager()->getDefinitions();
    $this->assertEqualsCanonicalizing([
      'fixture_theme_article_video',
      'fixture_theme_contact_early',
      'fixture_theme_contact_late',
      'fixture_base_contact',
      'other_theme_contact',
    ], array_keys($definitions));
    $this->assertSame('fixture_theme', $definitions['fixture_theme_article_video']['provider']);
    $this->assertSame('article-video', $definitions['fixture_theme_article_video']['component']);
    $this->assertSame(10, $definitions['fixture_theme_contact_late']['priority']);
    $this->assertSame(0, $definitions['fixture_theme_article_video']['priority']);
  }

  /**
   * Plugins run by priority, then plugin ID, and skip unrelated themes.
   *
   * The default theme here is unrelated to the active chain. The active chain
   * supplied the component, so its plugin must not run.
   *
   * @covers ::getMappersForComponent
   * @covers ::getEligibleThemes
   */
  public function testActiveThemeHitIgnoresUnrelatedDefaultTheme(): void {
    $mappers = $this->manager('fixture_theme', 'other_theme')->getMappersForComponent('contact', 'fixture_theme');
    // Priority -5, then the two priority 0 plugins by ID, then priority 10.
    $this->assertSame([
      'fixture_theme_contact_early',
      'fixture_base_contact',
      'fixture_theme_contact_late',
    ], array_keys($mappers));
  }

  /**
   * A base theme that supplied the component counts as the active chain.
   *
   * @covers ::getEligibleThemes
   */
  public function testBaseThemeSupplierUsesActiveChain(): void {
    $mappers = $this->manager('fixture_theme', 'other_theme')->getMappersForComponent('contact', 'fixture_base');
    $this->assertContains('fixture_theme_contact_early', array_keys($mappers));
    $this->assertNotContains('other_theme_contact', array_keys($mappers));
  }

  /**
   * When the registry fell back to the default theme, its plugins run.
   *
   * @covers ::getEligibleThemes
   */
  public function testFallbackToDefaultThemeUsesDefaultChain(): void {
    $mappers = $this->manager('fixture_theme', 'other_theme')->getMappersForComponent('contact', 'other_theme');
    $this->assertSame(['other_theme_contact'], array_keys($mappers));
  }

  /**
   * An unknown or module supplier counts as the active chain.
   *
   * @covers ::getEligibleThemes
   */
  public function testUnknownSupplierUsesActiveChain(): void {
    $manager = $this->manager('fixture_theme', 'other_theme');
    foreach ([NULL, 'some_module'] as $provider) {
      $ids = array_keys($manager->getMappersForComponent('contact', $provider));
      $this->assertContains('fixture_theme_contact_late', $ids);
      $this->assertNotContains('other_theme_contact', $ids);
    }
  }

  /**
   * A component without plugins gets none.
   *
   * @covers ::getMappersForComponent
   */
  public function testUnknownComponent(): void {
    $this->assertSame([], $this->manager()->getMappersForComponent('nothing'));
  }

  /**
   * SelectDefinitions orders and filters without discovery.
   *
   * @covers ::selectDefinitions
   */
  public function testSelectDefinitions(): void {
    $definitions = [
      'z_mod' => ['component' => 'c', 'provider' => 'mod', 'priority' => 0],
      'a_mod' => ['component' => 'c', 'provider' => 'mod', 'priority' => 0],
      'low' => ['component' => 'c', 'provider' => 'mod', 'priority' => -1],
      'high' => ['component' => 'c', 'provider' => 'mod', 'priority' => 5],
      'theme_in' => ['component' => 'c', 'provider' => 'th', 'priority' => 0],
      'theme_out' => ['component' => 'c', 'provider' => 'gone', 'priority' => 0],
      'other_component' => ['component' => 'd', 'provider' => 'mod', 'priority' => 0],
    ];
    $selected = ParagraphMapperManager::selectDefinitions(
      $definitions,
      'c',
      ['th'],
      static fn (string $provider): bool => in_array($provider, ['th', 'gone'], TRUE),
    );
    $this->assertSame(['low', 'a_mod', 'theme_in', 'z_mod', 'high'], array_keys($selected));
  }

}
