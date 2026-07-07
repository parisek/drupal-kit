<?php

namespace Drupal\Tests\custom_components\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\custom_components\Services\MediaArrayBuilder;
use Drupal\file\Entity\File;
use Drupal\image\Entity\ImageStyle;
use Drupal\media\Entity\Media;

/**
 * Shared base for MediaArrayBuilder kernel tests.
 *
 * Provides minimal fixtures the builder methods need: a real `image`
 * media type backed by the `image` source plugin, a sample PNG written
 * to public://, and helper methods to create Media + File entities
 * around it.
 *
 * Each test class chooses which fixtures to create in its own setUp() —
 * the heavy ones (SVG file, image style for transform tests) are not
 * built unconditionally to keep test setup cost down.
 *
 * @group custom_components
 */
abstract class MediaArrayBuilderKernelTestBase extends KernelTestBase {

  use MediaTypeCreationTrait;

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
    'field',
    'text',
    'filter',
    'image',
  ];

  /**
   * The builder under test (fetched from the real container).
   */
  protected MediaArrayBuilder $builder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Order matters: user before file (revision_user), file before media.
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', ['file_usage']);

    $this->builder = $this->container->get('custom_components.media_array_builder');
  }

  /**
   * Creates a 1x1 transparent PNG written to public:// as a real file.
   *
   * @param string $name
   *   File name within public:// (default: 'test.png').
   *
   * @return \Drupal\file\FileInterface
   *   The saved File entity.
   */
  protected function createTestPngFile(string $name = 'test.png'): File {
    // Smallest valid PNG (1x1 transparent pixel).
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

  /**
   * Write arbitrary content to public:// and return a saved File entity.
   */
  protected function createTestFile(string $name, string $content, string $mime): File {
    $uri = 'public://' . $name;
    file_put_contents($uri, $content);

    $file = File::create([
      'uri' => $uri,
      'filename' => $name,
      'filemime' => $mime,
      'status' => 1,
    ]);
    $file->save();
    return $file;
  }

  /**
   * Create an Image media entity wrapping the given File.
   */
  protected function createTestImageMedia(File $file, string $bundle = 'image'): Media {
    $type = $this->container->get('entity_type.manager')
      ->getStorage('media_type')
      ->load($bundle);
    if (!$type) {
      $type = $this->createMediaType('image', ['id' => $bundle]);
    }

    $source_field = $type->getSource()->getSourceFieldDefinition($type)->getName();

    $media = Media::create([
      'bundle' => $bundle,
      'name' => $file->getFilename(),
      $source_field => ['target_id' => $file->id(), 'alt' => 'Sample alt'],
    ]);
    $media->save();
    return $media;
  }

  /**
   * Create an ImageStyle with a single scale effect; idempotent.
   */
  protected function createImageStyle(string $name = 'thumbnail_test', int $width = 100): ImageStyle {
    $existing = ImageStyle::load($name);
    if ($existing) {
      return $existing;
    }
    $style = ImageStyle::create(['name' => $name, 'label' => $name]);
    $style->addImageEffect([
      'id' => 'image_scale',
      'weight' => 0,
      'data' => ['width' => $width, 'height' => $width, 'upscale' => TRUE],
    ]);
    $style->save();
    return $style;
  }

}
