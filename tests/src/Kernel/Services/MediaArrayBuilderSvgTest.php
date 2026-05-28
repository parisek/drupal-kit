<?php

namespace Drupal\Tests\custom_components\Kernel\Services;

use Drupal\Tests\custom_components\Kernel\MediaArrayBuilderKernelTestBase;
use Drupal\media\Entity\Media;

/**
 * @coversDefaultClass \Drupal\custom_components\Services\MediaArrayBuilder
 * @group custom_components
 */
class MediaArrayBuilderSvgTest extends MediaArrayBuilderKernelTestBase {

  /**
   * @covers ::buildSvg
   */
  public function testBuildSvgReturnsImageArrayWithDimensions(): void {
    $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32"/></svg>';
    $file = $this->createTestFile('logo.svg', $svg, 'image/svg+xml');
    $type = $this->createMediaType('image', ['id' => 'svg']);
    $source_field = $type->getSource()->getSourceFieldDefinition($type)->getName();

    $media = Media::create([
      'bundle' => 'svg',
      'name' => $file->getFilename(),
      $source_field => ['target_id' => $file->id(), 'alt' => 'Logo alt'],
    ]);
    $media->save();

    $data = $this->builder->buildSvg($media);

    $this->assertCount(1, $data);
    $image = $data[0];
    $this->assertArrayHasKey('src', $image);
    $this->assertStringContainsString('logo.svg', $image['src']);
    $this->assertSame('image/svg+xml', $image['type']);
    $this->assertArrayHasKey('alt', $image);
    $this->assertSame(32, $image['width']);
    $this->assertSame(32, $image['height']);
  }

  /**
   * @covers ::buildSvg
   *
   * Source returning a non-file id (missing fixture) yields an empty
   * array — the documented null-safe path.
   */
  public function testBuildSvgMissingFileReturnsEmpty(): void {
    $type = $this->createMediaType('image', ['id' => 'svg_missing']);
    $source_field = $type->getSource()->getSourceFieldDefinition($type)->getName();

    $media = Media::create([
      'bundle' => 'svg_missing',
      'name' => 'orphan',
      $source_field => ['target_id' => 999999, 'alt' => ''],
    ]);
    $media->save();

    $this->assertSame([], $this->builder->buildSvg($media));
  }

  /**
   * @covers ::buildSvg
   *
   * Malformed SVG drops width/height but keeps the rest of the shape.
   */
  public function testBuildSvgOmitsDimensionsForMalformedSvg(): void {
    $file = $this->createTestFile('broken.svg', 'not xml', 'image/svg+xml');
    $type = $this->createMediaType('image', ['id' => 'svg_broken']);
    $source_field = $type->getSource()->getSourceFieldDefinition($type)->getName();

    $media = Media::create([
      'bundle' => 'svg_broken',
      'name' => $file->getFilename(),
      $source_field => ['target_id' => $file->id(), 'alt' => ''],
    ]);
    $media->save();

    $data = $this->builder->buildSvg($media);

    $this->assertCount(1, $data);
    $image = $data[0];
    $this->assertArrayHasKey('src', $image);
    $this->assertArrayNotHasKey('width', $image);
    $this->assertArrayNotHasKey('height', $image);
  }

  /**
   * @covers ::getSvgViewBoxDimensions
   */
  public function testGetSvgViewBoxDimensionsParsesViewBox(): void {
    $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect width="24" height="24"/></svg>';
    $file = $this->createTestFile('icon.svg', $svg, 'image/svg+xml');

    $dimensions = $this->builder->getSvgViewBoxDimensions($file->getFileUri());

    $this->assertSame(['width' => 24, 'height' => 24], $dimensions);
  }

  /**
   * @covers ::getSvgViewBoxDimensions
   */
  public function testGetSvgViewBoxDimensionsHandlesCommaSeparatedViewBox(): void {
    $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0,0,100,50"><rect width="100" height="50"/></svg>';
    $file = $this->createTestFile('comma.svg', $svg, 'image/svg+xml');

    $dimensions = $this->builder->getSvgViewBoxDimensions($file->getFileUri());

    $this->assertSame(['width' => 100, 'height' => 50], $dimensions);
  }

  /**
   * @covers ::getSvgViewBoxDimensions
   */
  public function testGetSvgViewBoxDimensionsReadsExplicitWidthHeight(): void {
    $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="48" height="48"><rect width="48" height="48"/></svg>';
    $file = $this->createTestFile('explicit.svg', $svg, 'image/svg+xml');

    $dimensions = $this->builder->getSvgViewBoxDimensions($file->getFileUri());

    $this->assertSame(['width' => 48, 'height' => 48], $dimensions);
  }

  /**
   * @covers ::getSvgViewBoxDimensions
   */
  public function testGetSvgViewBoxDimensionsReturnsNullForMalformedSvg(): void {
    $file = $this->createTestFile('broken.svg', 'this is not xml', 'image/svg+xml');

    $dimensions = $this->builder->getSvgViewBoxDimensions($file->getFileUri());

    $this->assertNull($dimensions);
  }

  /**
   * @covers ::getSvgViewBoxDimensions
   */
  public function testGetSvgViewBoxDimensionsReturnsNullWhenNoDimensionInfo(): void {
    $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>';
    $file = $this->createTestFile('nodim.svg', $svg, 'image/svg+xml');

    $dimensions = $this->builder->getSvgViewBoxDimensions($file->getFileUri());

    $this->assertNull($dimensions);
  }

  /**
   * @covers ::getSvgViewBoxDimensions
   */
  public function testGetSvgViewBoxDimensionsRejectsZeroOrNegativeDimensions(): void {
    $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 0 0"><rect/></svg>';
    $file = $this->createTestFile('zero.svg', $svg, 'image/svg+xml');

    $dimensions = $this->builder->getSvgViewBoxDimensions($file->getFileUri());

    $this->assertNull($dimensions);
  }

}
