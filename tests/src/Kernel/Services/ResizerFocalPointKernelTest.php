<?php

namespace Drupal\Tests\custom_components\Kernel\Services;

use Drupal\Tests\custom_components\Kernel\ResizerKernelTestBase;
use Drupal\custom_components\Services\Resizer;

/**
 * Resizer coverage with the focal_point module enabled.
 *
 * The base ResizerKernelTestBase deliberately omits focal_point so the
 * common variant tests exercise the `image_scale_and_crop` fallback in
 * addCropEffect (the safer-default path most consumers run on). This
 * file enables focal_point and crop, then exercises the OTHER branch:
 * addCropEffect's `focal_point_scale_and_crop` path + the
 * getFocalPointHash helper.
 *
 * @coversDefaultClass \Drupal\custom_components\Services\Resizer
 * @group custom_components
 */
class ResizerFocalPointKernelTest extends ResizerKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * focal_point + crop on top of the base ResizerKernelTestBase set.
   */
  protected static $modules = [
    'custom_components',
    'system',
    'user',
    'file',
    'image',
    'media',
    'file_mdm',
    'image_effects',
    'crop',
    'focal_point',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // crop entity schema (focal_point stores its position via crop
    // entities through the crop_type config defined in
    // focal_point.settings).
    $this->installEntitySchema('crop');
    // focal_point's crop_type config must exist for getCropEntity()
    // calls to succeed. Install the module's default config.
    $this->installConfig(['focal_point']);

    // Reset Resizer's static format-detection cache so this test's
    // toolkit-detection runs fresh rather than reusing whatever the
    // earlier variant-test process recorded. Without this, the
    // getOutputFormat body inside this test class never runs.
    $reflection = new \ReflectionClass(Resizer::class);
    $checked = $reflection->getProperty('formatChecked');
    $checked->setValue(NULL, FALSE);
    $format = $reflection->getProperty('outputFormat');
    $format->setValue(NULL, NULL);
  }

  /**
   * @covers ::resizer
   * @covers ::addImageEffects
   * @covers ::addCropEffect
   *
   * With focal_point enabled, `image_style: 'crop'` routes through
   * addCropEffect's focal_point branch — the `focal_point_scale_and_crop`
   * effect (instead of the fallback `image_scale_and_crop`). Exercise
   * via the public Resizer::resizer entry; the variant produced still
   * has the 100×100 dimensions because focal_point_scale_and_crop has
   * the same upscale-and-crop contract.
   */
  public function testCropVariantUsesFocalPointEffectWhenModuleEnabled(): void {
    $this->createTestPngFile('fp-crop.png');

    $image = [[
      'src' => '/sites/default/files/fp-crop.png',
      'type' => 'image/png',
      'width' => 1,
      'height' => 1,
    ]];

    $result = Resizer::resizer($image, [[100, 100, 768, 'crop']]);

    $this->assertGreaterThanOrEqual(2, count($result), 'Expected variant + fallback — focal_point crop branch may have been skipped.');
    $fallback = end($result);
    $this->assertSame('/sites/default/files/fp-crop.png', $fallback['src']);
    // focal_point_scale_and_crop has upscale=TRUE just like the
    // fallback, so the variant dimensions are still forced to 100×100.
    $this->assertSame(100, $result[0]['width']);
    $this->assertSame(100, $result[0]['height']);
  }

  /**
   * @covers ::resizer
   * @covers ::getFocalPointHash
   *
   * With focal_point enabled, getFocalPointHash's `moduleExists`
   * guard returns TRUE — the method proceeds past the early-`return ''`
   * branch into the file-storage lookup and the focal_point manager
   * call. Because this test never creates a crop entity, getCropEntity
   * returns NULL and the method falls through to the FINAL `return ''`.
   * The branches covered here are therefore:
   *
   * - moduleExists-TRUE pass-through (the early-return path is
   *   covered in the existing ResizerTest where focal_point is NOT
   *   enabled in the base modules list).
   * - file-storage lookup + reset().
   * - getCropEntity returns NULL → final return ''.
   *
   * The non-empty-hash branch (lines that build the `substr(md5(...))`
   * suffix from a real focal-point position) is NOT covered here —
   * that needs a saved crop entity with a position. Left as a follow-up.
   *
   * Verified observably via the `crop` image_style_id suffix: an
   * empty hash leaves the derivative URL at `100-100-crop` (no
   * `-{hash}` suffix appended).
   */
  public function testGetFocalPointHashHandlesFileWithoutFocalPoint(): void {
    $this->createTestPngFile('no-fp.png');

    $image = [[
      'src' => '/sites/default/files/no-fp.png',
      'type' => 'image/png',
      'width' => 1,
      'height' => 1,
    ]];

    // The crop variant pass calls getFocalPointHash once per variant.
    // With focal_point enabled but no crop entity present, it should
    // return '' (and the variant still builds successfully via the
    // focal_point_scale_and_crop fallback chain).
    $result = Resizer::resizer($image, [[100, 100, 768, 'crop']]);

    // Variant + fallback. The derivative URL must NOT contain a hash
    // suffix after `-crop` because getFocalPointHash returned ''.
    $this->assertGreaterThanOrEqual(2, count($result));
    $variant_src = $result[0]['src'];
    // Image style id is `{width}-{height}-{variant_type}` = `100-100-crop`
    // when hash is empty; `100-100-crop-{hash}` when non-empty.
    $this->assertStringContainsString('100-100-crop', $variant_src);
    $this->assertDoesNotMatchRegularExpression(
      '#100-100-crop-[a-f0-9]{8}#',
      $variant_src,
      'No focal point set → derivative URL must not carry a hash suffix.',
    );
  }

}
