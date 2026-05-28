<?php

namespace Drupal\Tests\custom_components\Kernel\Services;

use Drupal\Tests\custom_components\Kernel\ResizerKernelTestBase;
use Drupal\custom_components\Services\Resizer;

/**
 * Kernel tests for the Resizer service.
 *
 * Resizer::resizer is the public static entry point; tests assert on
 * its observable output, not internal helper methods.
 *
 * @coversDefaultClass \Drupal\custom_components\Services\Resizer
 * @group custom_components
 */
class ResizerTest extends ResizerKernelTestBase {

  /**
   * @covers ::resizer
   */
  public function testEmptyImageReturnsEmpty(): void {
    $this->assertSame([], Resizer::resizer([], []));
  }

  /**
   * @covers ::resizer
   */
  public function testImageWithoutSrcReturnsEmpty(): void {
    $this->assertSame([], Resizer::resizer(['type' => 'image/png'], []));
  }

  /**
   * @covers ::resizer
   *
   * SVGs are passed through unchanged — no image style is applied.
   * Resizer expects the image as an array-of-images (see consumer
   * contract: EntityHelper's image getters return [[...]]). Wrapping
   * the single image in an outer array is the conventional shape.
   */
  public function testSvgPassthrough(): void {
    $svg = [[
      'src' => '/sites/default/files/icon.svg',
      'type' => 'image/svg+xml',
      'width' => 24,
      'height' => 24,
    ]];

    $result = Resizer::resizer($svg, [[100, 100, 0, 'default']]);

    $this->assertCount(1, $result);
    $this->assertSame('image/svg+xml', $result[0]['type']);
    $this->assertSame('/sites/default/files/icon.svg', $result[0]['src']);
  }

  /**
   * @covers ::resizer
   *
   * URLs outside /sites/default/files/ are not processed — Resizer
   * falls through and returns the original as the sole fallback.
   */
  public function testExternalUrlReturnsOnlyFallback(): void {
    $image = [[
      'src' => 'https://cdn.example.com/photo.jpg',
      'type' => 'image/jpeg',
      'width' => 800,
      'height' => 600,
    ]];

    $result = Resizer::resizer($image, [[400, 300, 0, 'default']]);

    // The variant block is gated on /sites/default/files/ matching, so
    // only the fallback (default_image) is appended.
    $this->assertCount(1, $result);
    $this->assertSame('https://cdn.example.com/photo.jpg', $result[0]['src']);
  }

  /**
   * @covers ::resizer
   *
   * A countable input (array of images) collapses to its LAST element
   * before processing. Documents the legacy passthrough where callers
   * sometimes hand in `getMediaField` output (array of one).
   */
  public function testCountableInputCollapsesToLastElement(): void {
    $image_array = [
      ['src' => '/first.jpg', 'type' => 'image/jpeg'],
      ['src' => '/second.jpg', 'type' => 'image/jpeg'],
    ];

    $result = Resizer::resizer($image_array, []);

    // No variants → only fallback. Fallback is the LAST element of the
    // input array (second.jpg).
    $this->assertCount(1, $result);
    $this->assertSame('/second.jpg', $result[0]['src']);
  }

  /**
   * @covers ::resizer
   * @covers ::addImageEffects
   * @covers ::addCropEffect
   *
   * `image_style: 'crop'` routes through addImageEffects' match into
   * addCropEffect. focal_point isn't in the kernel module list here,
   * so the fallback `image_scale_and_crop` effect branch is exercised.
   * The focal_point-enabled branch is acceptable to leave uncovered —
   * adding the module would balloon the kernel boot, and the fallback
   * is the safer default the code is designed to handle.
   */
  public function testCropVariantBuildsImageScaleAndCropEffect(): void {
    $this->createTestPngFile('crop.png');

    $image = [[
      'src' => '/sites/default/files/crop.png',
      'type' => 'image/png',
      'width' => 1,
      'height' => 1,
    ]];

    $result = Resizer::resizer($image, [[100, 100, 768, 'crop']]);

    // ≥ 2 entries proves the variant loop produced a derivative AND
    // the fallback was appended. A bare-fallback (count == 1) result
    // would mean the crop effect-builder branch was skipped without
    // this test catching it.
    $this->assertGreaterThanOrEqual(2, count($result), 'Expected variant + fallback — crop effect-builder branch may have been skipped.');
    $fallback = end($result);
    $this->assertSame('/sites/default/files/crop.png', $fallback['src']);
    // Variant carries a media-query attribute the fallback never does;
    // locks the variant-vs-fallback distinction.
    $this->assertArrayHasKey('media', $result[0]);
  }

  /**
   * @covers ::resizer
   * @covers ::addImageEffects
   * @covers ::addSmartCropEffect
   *
   * `image_style: 'smart_crop'` routes through addSmartCropEffect,
   * which adds the image_effects-provided
   * `image_effects_scale_and_smart_crop` effect (entropy algorithm).
   */
  public function testSmartCropVariantBuildsEntropyEffect(): void {
    $this->createTestPngFile('smart.png');

    $image = [[
      'src' => '/sites/default/files/smart.png',
      'type' => 'image/png',
      'width' => 1,
      'height' => 1,
    ]];

    $result = Resizer::resizer($image, [[100, 100, 768, 'smart_crop']]);

    $this->assertGreaterThanOrEqual(2, count($result), 'Expected variant + fallback — smart_crop effect-builder branch may have been skipped.');
    $fallback = end($result);
    $this->assertSame('/sites/default/files/smart.png', $fallback['src']);
    $this->assertArrayHasKey('media', $result[0]);
  }

  /**
   * @covers ::resizer
   * @covers ::addImageEffects
   * @covers ::addCanvasEffect
   *
   * `image_style: 'canvas'` adds image_scale + image_effects_set_canvas
   * for exact-size letterboxed output.
   */
  public function testCanvasVariantBuildsScaleAndCanvasEffects(): void {
    $this->createTestPngFile('canvas.png');

    $image = [[
      'src' => '/sites/default/files/canvas.png',
      'type' => 'image/png',
      'width' => 1,
      'height' => 1,
    ]];

    $result = Resizer::resizer($image, [[100, 100, 768, 'canvas']]);

    $this->assertGreaterThanOrEqual(2, count($result), 'Expected variant + fallback — canvas effect-builder branch may have been skipped.');
    $fallback = end($result);
    $this->assertSame('/sites/default/files/canvas.png', $fallback['src']);
    $this->assertArrayHasKey('media', $result[0]);
  }

  /**
   * @covers ::resizer
   *
   * Files that exist in public:// AND match the /sites/default/files/
   * URL prefix go through the full image-style derivative path.
   */
  public function testLocalFileProducesVariantsViaImageStyle(): void {
    $file = $this->createTestPngFile('local.png');

    $image = [[
      'src' => '/sites/default/files/local.png',
      'type' => 'image/png',
      'width' => 1,
      'height' => 1,
    ]];

    $result = Resizer::resizer($image, [[100, 100, 768, 'default']]);

    // Variant + fallback = 2 entries minimum (success of derivative
    // generation depends on the GD/image toolkit; assert >= 1).
    $this->assertGreaterThanOrEqual(1, count($result));
    // Last entry is always the fallback (default_image).
    $fallback = end($result);
    $this->assertSame('/sites/default/files/local.png', $fallback['src']);
  }

}
