<?php

namespace Drupal\Tests\custom_components\Kernel\Services;

use Drupal\Tests\custom_components\Kernel\MediaArrayBuilderKernelTestBase;
use Drupal\media\Entity\Media;

/**
 * @coversDefaultClass \Drupal\custom_components\Services\MediaArrayBuilder
 * @group custom_components
 */
class MediaArrayBuilderVideoTest extends MediaArrayBuilderKernelTestBase {

  /**
   * @covers ::buildVideo
   */
  public function testBuildVideoReturnsFileMetadata(): void {
    // Use a 'file' media bundle (generic file source) so we can host
    // a faux MP4 without needing an actual video codec.
    $file = $this->createTestFile('clip.mp4', 'not-really-an-mp4', 'video/mp4');
    $type = $this->createMediaType('file', ['id' => 'video']);
    $source_field = $type->getSource()->getSourceFieldDefinition($type)->getName();

    $media = Media::create([
      'bundle' => 'video',
      'name' => $file->getFilename(),
      $source_field => ['target_id' => $file->id()],
    ]);
    $media->save();

    $data = $this->builder->buildVideo($media);

    $this->assertSame('clip.mp4', $data['title']);
    $this->assertSame('video/mp4', $data['type']);
    $this->assertArrayHasKey('src', $data);
    $this->assertArrayHasKey('size', $data);
  }

  /**
   * @covers ::buildVideo
   */
  public function testBuildVideoReturnsEmptyForMissingFile(): void {
    // Build a media entity that references a non-existent file id.
    $type = $this->createMediaType('file', ['id' => 'video_missing']);
    $source_field = $type->getSource()->getSourceFieldDefinition($type)->getName();

    $media = Media::create([
      'bundle' => 'video_missing',
      'name' => 'orphan',
      $source_field => ['target_id' => 999999],
    ]);
    $media->save();

    $this->assertSame([], $this->builder->buildVideo($media));
  }

  // Note: buildRemoteVideo's full integration test needs the
  // media_oembed source plugin + a real oembed Media bundle, which
  // requires network mocking. Deferred to v1.3.0 as a focused PR.
  // The iframe-extraction logic is exercised end-to-end in production
  // via the htdvere consumer; the YouTube regex itself is pure PHP
  // and stable.
}
