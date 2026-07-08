<?php

namespace Drupal\Tests\drupal_kit\Kernel\Services;

use Drupal\Tests\drupal_kit\Kernel\MediaArrayBuilderKernelTestBase;
use Drupal\media\Entity\Media;

/**
 * @coversDefaultClass \Drupal\drupal_kit\Services\MediaArrayBuilder
 * @group drupal_kit
 */
class MediaArrayBuilderDocumentTest extends MediaArrayBuilderKernelTestBase {

  /**
   * @covers ::buildDocument
   */
  public function testBuildDocumentReturnsFileMetadata(): void {
    $file = $this->createTestFile('brochure.pdf', '%PDF-1.4 stub', 'application/pdf');
    $type = $this->createMediaType('file', ['id' => 'document']);
    $source_field = $type->getSource()->getSourceFieldDefinition($type)->getName();

    $media = Media::create([
      'bundle' => 'document',
      'name' => $file->getFilename(),
      $source_field => ['target_id' => $file->id()],
    ]);
    $media->save();

    $data = $this->builder->buildDocument($media);

    $this->assertSame('brochure.pdf', $data['title']);
    $this->assertSame('application/pdf', $data['type']);
    $this->assertArrayHasKey('uri', $data);
    $this->assertArrayHasKey('size', $data);
    $this->assertArrayHasKey('url', $data);
  }

}
