<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Kernel\DataLayer;

use Drupal\KernelTests\KernelTestBase;

/**
 * Pins that the libraries the hooks attach exist and point at real files (#160).
 *
 * A hook that attaches a library name nobody declared fails silently in the
 * browser, so the names are checked against the library discovery.
 *
 * @group drupal_kit
 */
class DataLayerLibrariesKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['drupal_kit', 'system'];

  /**
   * Both libraries exist and their scripts are in the module.
   *
   * @dataProvider libraryProvider
   * @phpstan-param non-empty-string $file
   */
  public function testLibraryExistsAndItsScriptIsThere(string $library, string $file): void {
    $this->assertNotSame('', $file);
    $definition = $this->container->get('library.discovery')->getLibraryByName('drupal_kit', $library);

    $this->assertNotFalse($definition, "drupal_kit/$library is declared");
    $files = array_column($definition['js'], 'data');
    $this->assertCount(1, $files);
    $this->assertStringEndsWith($file, $files[0]);
    $module_path = $this->container->get('extension.list.module')->getPath('drupal_kit');
    $this->assertFileExists($this->root . '/' . $module_path . '/' . $file);
  }

  /**
   * The two libraries and their script files.
   *
   * @return array<string, array<int, string>>
   *   The library name and the file it loads.
   */
  public static function libraryProvider(): array {
    return [
      'lead command' => ['datalayer', 'js/datalayer.js'],
      'click events' => ['datalayer_events', 'js/datalayer-events.js'],
    ];
  }

}
