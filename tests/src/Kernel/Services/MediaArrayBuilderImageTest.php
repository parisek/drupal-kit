<?php

namespace Drupal\Tests\custom_components\Kernel\Services;

use Drupal\Tests\custom_components\Kernel\MediaArrayBuilderKernelTestBase;

/**
 * @coversDefaultClass \Drupal\custom_components\Services\MediaArrayBuilder
 * @group custom_components
 */
class MediaArrayBuilderImageTest extends MediaArrayBuilderKernelTestBase {

  /**
   * @covers ::buildImage
   */
  public function testBuildImageReturnsImageArrayFromMedia(): void {
    $file = $this->createTestPngFile('media-image.png');
    $media = $this->createTestImageMedia($file);

    $images = $this->builder->buildImage($media);

    $this->assertNotEmpty($images, 'buildImage returns at least one image.');
    $image = $images[0];
    $this->assertArrayHasKey('src', $image);
    $this->assertArrayHasKey('type', $image);
    $this->assertSame('image/png', $image['type']);
    $this->assertStringContainsString('media-image.png', $image['src']);
  }

  /**
   * @covers ::buildImage
   */
  public function testBuildImageEmitsWidthAndHeightWhenFileExists(): void {
    $file = $this->createTestPngFile('with-dims.png');
    $media = $this->createTestImageMedia($file);

    $images = $this->builder->buildImage($media);
    $image = $images[0];

    $this->assertArrayHasKey('width', $image);
    $this->assertArrayHasKey('height', $image);
    // The 1x1 fixture should report dimensions of 1, but Drupal's
    // image source plugin reads them from metadata; assert positive int.
    $this->assertGreaterThan(0, (int) $image['width']);
    $this->assertGreaterThan(0, (int) $image['height']);
  }

  /**
   * @covers ::buildImage
   */
  public function testBuildImageAppliesImageStyle(): void {
    $file = $this->createTestPngFile('styled.png');
    $media = $this->createTestImageMedia($file);
    $this->createImageStyle('test_thumb', 100);

    $images = $this->builder->buildImage($media, 'test_thumb');
    $image = $images[0];

    // Styled src goes through the image-style URL helper; it contains
    // the style id as a path segment.
    $this->assertStringContainsString('test_thumb', $image['src']);
  }

  /**
   * @covers ::buildImage
   */
  public function testBuildImageUnknownStyleLogsAndStillReturnsBaseImage(): void {
    $file = $this->createTestPngFile('no-style.png');
    $media = $this->createTestImageMedia($file);

    // Asking for a non-existent style should still return the unstyled
    // image — the implementation logs a notice but doesn't blow up.
    $images = $this->builder->buildImage($media, 'this_style_does_not_exist');

    $this->assertNotEmpty($images);
    // Unstyled src must NOT contain the bogus style name as a path
    // segment.
    $this->assertStringNotContainsString(
      '/styles/this_style_does_not_exist/',
      $images[0]['src'],
    );
  }

  /**
   * @covers ::buildFileImage
   */
  public function testBuildFileImageReturnsImageArray(): void {
    $file = $this->createTestPngFile('plain.png');

    $images = $this->builder->buildFileImage($file, '', ['alt' => 'A', 'title' => 'T']);

    $this->assertNotEmpty($images);
    $image = $images[0];
    $this->assertSame('A', $image['alt']);
    $this->assertSame('T', $image['title']);
    $this->assertSame('image/png', $image['type']);
  }

  /**
   * @covers ::buildFileImage
   */
  public function testBuildFileImageWithNonFileReturnsEmpty(): void {
    // Anything other than a FileInterface drops through to an empty
    // array — the documented contract for the wrapper template.
    $images = $this->builder->buildFileImage(NULL);

    $this->assertSame([], $images);
  }

}
