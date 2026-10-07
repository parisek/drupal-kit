<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Unit\Commands;

use Composer\Autoload\ClassLoader;
use Robo\ClassDiscovery\RelativeNamespaceDiscovery;
use Drupal\drupal_kit\Drush\Commands\ConfigApplierCommands;
use Drupal\drupal_kit\Services\ConfigApplier;
use Drush\Commands\DrushCommands;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Pins what Drush needs to list and build the command classes.
 *
 * Drush 12 and later find a module's commands only in `src/Drush/Commands`.
 * Drush 13 builds a command through its static `create()` method. A class
 * that fails either rule works when a test builds it by hand, and the command
 * is still missing from `drush list`. These tests use Drush's own discovery
 * and its own autowiring.
 *
 * @group drupal_kit
 */
final class DrushCommandDiscoveryTest extends TestCase {

  /**
   * Drush's PSR-4 discovery finds the config command.
   */
  public function testDrushDiscoversTheConfigApplierCommand(): void {
    // The same call Drush makes in ServiceManager::discoverPsr4Commands().
    $classes = (new RelativeNamespaceDiscovery($this->composerLoader()))
      ->setRelativeNamespace('Drush\Commands')
      ->setSearchPattern('/.*(Command)s?\.php$/')
      ->getClasses();

    $this->assertContains(ConfigApplierCommands::class, $classes);
  }

  /**
   * Drush builds the command from the container, with the kit's service.
   */
  public function testDrushBuildsTheCommandFromTheContainer(): void {
    $applier = $this->createMock(ConfigApplier::class);
    $container = new ContainerBuilder();
    $container->set('drupal_kit.config_applier', $applier);

    $this->assertInstanceOf(ConfigApplierCommands::class, ConfigApplierCommands::create($container));
  }

  /**
   * No Drush command class sits outside src/Drush/Commands.
   *
   * The test loads each class under src and asks whether it extends
   * DrushCommands, so an alias or an indirect subclass is caught too. A class
   * that does, anywhere else under src, is a command Drush will not list.
   */
  public function testNoCommandClassSitsOutsideTheDrushDirectory(): void {
    $src = dirname(__DIR__, 4) . '/src';
    $misplaced = [];
    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
      if ($file->getExtension() !== 'php') {
        continue;
      }
      $relative = substr($file->getPathname(), strlen($src) + 1);
      if (str_starts_with($relative, 'Drush/Commands/')) {
        continue;
      }
      $class = 'Drupal\\drupal_kit\\' . str_replace(['/', '.php'], ['\\', ''], $relative);
      if (class_exists($class) && is_subclass_of($class, DrushCommands::class)) {
        $misplaced[] = $relative;
      }
    }

    $this->assertSame([], $misplaced, 'Drush finds commands only in src/Drush/Commands.');
  }

  /**
   * Returns the Composer class loader that serves the test run.
   *
   * Requiring the autoload file again returns the loader that is already
   * registered; it does not load a second copy.
   */
  private function composerLoader(): ClassLoader {
    $loader = require dirname(__DIR__, 4) . '/vendor/autoload.php';
    $this->assertInstanceOf(ClassLoader::class, $loader);

    return $loader;
  }

}
