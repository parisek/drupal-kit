<?php

namespace Drupal\Tests\custom_components\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\custom_components\Services\Resizer;
use Drupal\file\Entity\File;

/**
 * Shared base for Resizer kernel tests.
 *
 * Resizer::resizer is a static method, so tests don't fetch it from
 * the container — but the implementation calls \Drupal::config(),
 * \Drupal::moduleHandler(), and reads files from public://, so a real
 * kernel container is required.
 *
 * @group custom_components
 */
abstract class ResizerKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'custom_components',
    'system',
    'user',
    'file',
    'image',
    'media',
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
