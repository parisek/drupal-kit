<?php

namespace Drupal\Tests\drupal_kit\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\file\Entity\File;

/**
 * Shared base for Resizer kernel tests.
 *
 * Resizer is the drupal_kit.resizer service since #150. These tests
 * still reach it through the static Resizer::resizer() facade, which is
 * the entry point consumers are documented to use, and that facade needs
 * a real container. The implementation also reads files from public://.
 *
 * @group drupal_kit
 */
abstract class ResizerKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'drupal_kit',
    'system',
    'user',
    'file',
    'image',
    'media',
    'file_mdm',
    'image_effects',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
  }

  /**
   * Write a 1x1 transparent PNG to public:// and return a saved File.
   */
  protected function createTestPngFile(string $name = 'r.png'): File {
    $png = base64_decode(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
    );
    $uri = 'public://' . $name;
    file_put_contents($uri, $png);

    $file = File::create([
      'uri' => $uri,
      'filename' => $name,
      'filemime' => 'image/png',
      'status' => 1,
    ]);
    $file->save();
    return $file;
  }

}
