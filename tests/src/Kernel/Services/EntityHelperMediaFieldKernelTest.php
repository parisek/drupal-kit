<?php

namespace Drupal\Tests\custom_components\Kernel\Services;

use Drupal\Tests\custom_components\Kernel\EntityHelperMediaFieldsKernelTestBase;

/**
 * @coversDefaultClass \Drupal\custom_components\Services\EntityHelper
 * @group custom_components
 */
class EntityHelperMediaFieldKernelTest extends EntityHelperMediaFieldsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Pre-create the image media type so the field can reference it.
    $this->createMediaType('image', ['id' => 'image']);
    $this->attachField('illustration', 'entity_reference', [
      'target_type' => 'media',
    ], [
      'handler' => 'default:media',
      'handler_settings' => ['target_bundles' => ['image' => 'image']],
    ]);
  }

  /**
   * @covers ::getMediaField
   */
  public function testGetMediaFieldReturnsImageArrayFromReferencedMedia(): void {
    $file = $this->createTestPngFile('m.png');
    $media = $this->createTestImageMedia($file);

    $node = $this->createTestNode([
      'field_illustration' => ['target_id' => $media->id()],
    ]);

    $result = $this->entityHelper->getMediaField($node, 'illustration');
    // getMediaField dispatches by bundle; image bundle calls generateMediaImage
    // which returns array-of-images. normalizeReturnValue might unwrap if
    // single. Walk down to find an image with the 'src' key.
    $image = $result;
    while (is_array($image) && !isset($image['src']) && isset($image[0])) {
      $image = $image[0];
    }

    $this->assertIsArray($image);
    $this->assertArrayHasKey('src', $image);
    $this->assertStringContainsString('m.png', $image['src']);
  }

  /**
   * @covers ::getMediaField
   */
  public function testGetMediaFieldBubblesMediaCacheTagIntoCollector(): void {
    $file = $this->createTestPngFile('cache.png');
    $media = $this->createTestImageMedia($file);

    $node = $this->createTestNode([
      'field_illustration' => ['target_id' => $media->id()],
    ]);

    $this->entityHelper->getMediaField($node, 'illustration');
    $tags = $this->entityHelper->collectCacheMetadata()->getCacheTags();

    $this->assertContains('media:' . $media->id(), $tags);
  }

  /**
   * @covers ::getMediaField
   */
  public function testGetMediaFieldEmptyReturnsEmpty(): void {
    $node = $this->createTestNode();
    $result = $this->entityHelper->getMediaField($node, 'illustration');
    $this->assertTrue($result === [] || $result === '');
  }

  /**
   * @covers ::getMediaField
   */
  public function testGetMediaFieldMissingFieldReturnsFalse(): void {
    $node = $this->createTestNode();
    $this->assertFalse($this->entityHelper->getMediaField($node, 'nonexistent'));
  }

}
