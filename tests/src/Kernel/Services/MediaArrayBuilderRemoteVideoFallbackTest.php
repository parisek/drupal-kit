<?php

namespace Drupal\Tests\drupal_kit\Kernel\Services;

use Drupal\Tests\drupal_kit\Kernel\MediaArrayBuilderKernelTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\media\Entity\Media;

/**
 * @coversDefaultClass \Drupal\drupal_kit\Services\MediaArrayBuilder
 * @group drupal_kit
 *
 * Tests the no-resolver fallback of buildRemoteVideo() with real
 * entities. The media type uses the `file` source plugin (offline,
 * unlike oembed) so its source field (`field_media_file`) is distinct
 * from the separately-attached `field_media_image` — the fallback must
 * resolve the latter, never the source field.
 */
class MediaArrayBuilderRemoteVideoFallbackTest extends MediaArrayBuilderKernelTestBase {

  /**
   * A media entity whose source file differs from field_media_image.
   */
  protected Media $media;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $type = $this->createMediaType('file', ['id' => 'video_stub']);
    $source_field = $type->getSource()->getSourceFieldDefinition($type)->getName();

    FieldStorageConfig::create([
      'field_name' => 'field_media_image',
      'entity_type' => 'media',
      'type' => 'image',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_media_image',
      'entity_type' => 'media',
      'bundle' => 'video_stub',
      'label' => 'Image',
    ])->save();

    $source_file = $this->createTestPngFile('source.png');
    $thumbnail_file = $this->createTestPngFile('thumbnail.png');

    $this->media = Media::create([
      'bundle' => 'video_stub',
      'name' => 'Video stub',
      $source_field => ['target_id' => $source_file->id()],
      'field_media_image' => [
        'target_id' => $thumbnail_file->id(),
        'alt' => 'Fallback alt',
      ],
    ]);
    $this->media->save();
  }

  /**
   * The fallback resolves field_media_image, not the source field.
   *
   * @covers ::buildRemoteVideo
   */
  public function testNoResolverFallbackResolvesImageField(): void {
    $data = $this->builder->buildRemoteVideo($this->media);

    $this->assertArrayHasKey('image', $data);
    $this->assertCount(1, $data['image']);
    $this->assertStringContainsString(
      'thumbnail.png',
      $data['image'][0]['src'],
      'The fallback must return field_media_image, not the media source field',
    );
    $this->assertSame('Fallback alt', $data['image'][0]['alt']);
  }

  /**
   * The fallback matches what the EntityHelper resolver produces.
   *
   * @covers ::buildRemoteVideo
   */
  public function testNoResolverFallbackMatchesEntityHelperResolver(): void {
    $entity_helper = $this->container->get('drupal_kit.entity_helper');

    $with_resolver = $this->builder->buildRemoteVideo(
      $this->media,
      [$entity_helper, 'getImageField'],
    );
    $without_resolver = $this->builder->buildRemoteVideo($this->media);

    $this->assertSame($with_resolver['image'], $without_resolver['image']);
  }

}
