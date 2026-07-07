<?php

namespace Drupal\Tests\custom_components\Kernel;

use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;

/**
 * Test base for EntityHelper image/file/media field-getter kernel tests.
 *
 * Composes the test_article node fixture from
 * EntityHelperFieldsKernelTestBase with the PNG + Media entity helpers
 * from MediaArrayBuilderKernelTestBase.
 *
 * @group custom_components
 */
abstract class EntityHelperMediaFieldsKernelTestBase extends EntityHelperFieldsKernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'custom_components',
    'system',
    'user',
    'field',
    'node',
    'text',
    'filter',
    'file',
    'image',
    'media',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', ['file_usage']);
  }

  /**
   * Creates a 1x1 transparent PNG written to public:// as a real File entity.
   */
  protected function createTestPngFile(string $name = 'test.png'): File {
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
   * Create an Image media entity wrapping the given file.
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

}
