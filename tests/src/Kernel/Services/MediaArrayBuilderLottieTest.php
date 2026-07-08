<?php

namespace Drupal\Tests\drupal_kit\Kernel\Services;

use Drupal\Tests\drupal_kit\Kernel\MediaArrayBuilderKernelTestBase;
use Drupal\media\Entity\Media;

/**
 * @coversDefaultClass \Drupal\drupal_kit\Services\MediaArrayBuilder
 * @group drupal_kit
 */
class MediaArrayBuilderLottieTest extends MediaArrayBuilderKernelTestBase {

  /**
   * @covers ::buildLottie
   *
   * Lottie bundles store a JSON file. Backed by the generic `file`
   * source plugin (the dedicated drupal/lottie source plugin is a
   * contrib add-on; not required here for the coverage we need).
   */
  public function testBuildLottieReturnsFileSrcAndMime(): void {
    $json = '{"v":"5.5.7","fr":30,"ip":0,"op":60,"w":24,"h":24,"layers":[]}';
    $file = $this->createTestFile('icon.json', $json, 'application/json');
    $type = $this->createMediaType('file', ['id' => 'lottie']);
    $source_field = $type->getSource()->getSourceFieldDefinition($type)->getName();

    $media = Media::create([
      'bundle' => 'lottie',
      'name' => 'icon',
      $source_field => ['target_id' => $file->id()],
    ]);
    $media->save();

    $data = $this->builder->buildLottie($media);

    $this->assertArrayHasKey('src', $data);
    $this->assertSame('application/json', $data['type']);
    $this->assertStringContainsString('icon.json', $data['src']);
  }

  /**
   * @covers ::buildLottie
   *
   * Source returning a non-file id (missing fixture) yields an empty
   * array — the documented null-safe path.
   */
  public function testBuildLottieMissingFileReturnsEmpty(): void {
    $type = $this->createMediaType('file', ['id' => 'lottie_missing']);
    $source_field = $type->getSource()->getSourceFieldDefinition($type)->getName();

    $media = Media::create([
      'bundle' => 'lottie_missing',
      'name' => 'orphan',
      $source_field => ['target_id' => 999999],
    ]);
    $media->save();

    $this->assertSame([], $this->builder->buildLottie($media));
  }

}
