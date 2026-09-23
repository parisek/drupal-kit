<?php

namespace Drupal\Tests\drupal_kit\Kernel\Services;

use Drupal\Tests\drupal_kit\Kernel\ResizerKernelTestBase;
use Drupal\crop\Entity\Crop;
use Drupal\drupal_kit\Services\Resizer;

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
 * @coversDefaultClass \Drupal\drupal_kit\Services\Resizer
 * @group drupal_kit
 */
class ResizerFocalPointKernelTest extends ResizerKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Focal_point + crop on top of the base ResizerKernelTestBase set.
   */
  protected static $modules = [
    'drupal_kit',
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
    // Crop entity schema (focal_point stores its position via crop
    // entities through the crop_type config defined in
    // focal_point.settings).
    $this->installEntitySchema('crop');
    // focal_point's crop_type config must exist for getCropEntity()
    // calls to succeed. Install the module's default config.
    $this->installConfig(['focal_point']);

    // No cache reset here any more. The format-detection cache used to be
    // static, so one test's answer leaked into the next and this setUp had
    // to clear it by reflection — a test reaching into private static state
    // to keep the next test honest. Since #150 the cache is instance state
    // on the drupal_kit.resizer service, and a kernel test gets a fresh
    // container, so it starts clean by construction.
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
    ],
    ];

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
   * The non-empty-hash branch — the `substr(md5(...))` suffix built from
   * a real focal-point position — is covered by
   * testFocalPointPositionReachesTheImageStyleId() below, which saves
   * the crop entity this case deliberately does not.
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
    ],
    ];

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

  /**
   * A saved focal point changes the image style id.
   *
   * So the derivative is rebuilt when an editor moves the point.
   *
   * @covers ::resizer
   *
   * This is the branch the sibling case above cannot reach: with no crop
   * entity, getFocalPointHash() falls through to its final `return ''`
   * and the style id stays `100-100-crop`. Saving a crop with a position
   * makes it `100-100-crop-{hash}`.
   *
   * Why that matters beyond coverage: the hash IS the cache-busting
   * mechanism. Drupal keys a derivative by the style name, so without the
   * suffix an editor who repositions the focal point gets the old crop
   * served forever, and the only remedy is flushing image styles. A
   * regression here is silent and looks like a caching problem.
   *
   * The expected value is computed the same way the implementation does,
   * which would normally prove nothing — so the test also asserts the
   * suffix is ABSENT for a file with no crop, and that two different
   * positions produce two different ids. A hardcoded or constant hash
   * fails both.
   */
  public function testFocalPointPositionReachesTheImageStyleId(): void {
    $file = $this->createTestPngFile('fp-hash.png');
    $crop_type = $this->config('focal_point.settings')->get('crop_type');

    Crop::create([
      'type' => $crop_type,
      'entity_id' => $file->id(),
      'entity_type' => 'file',
      'uri' => $file->getFileUri(),
      'x' => 30,
      'y' => 70,
    ])->save();

    $hash = $this->styleIdSuffix('fp-hash.png');

    $this->assertNotSame('', $hash, 'A saved crop must add a hash suffix.');
    $this->assertSame(substr(md5('30-70'), 0, 8), $hash);
  }

  /**
   * Moving the point changes the hash; the same point keeps it.
   *
   * @covers ::resizer
   */
  public function testTheHashFollowsThePosition(): void {
    $a = $this->createTestPngFile('fp-a.png');
    $b = $this->createTestPngFile('fp-b.png');
    $c = $this->createTestPngFile('fp-c.png');
    $crop_type = $this->config('focal_point.settings')->get('crop_type');

    foreach ([[$a, 10, 20], [$b, 90, 80], [$c, 10, 20]] as [$file, $x, $y]) {
      Crop::create([
        'type' => $crop_type,
        'entity_id' => $file->id(),
        'entity_type' => 'file',
        'uri' => $file->getFileUri(),
        'x' => $x,
        'y' => $y,
      ])->save();
    }

    $first = $this->styleIdSuffix('fp-a.png');
    $moved = $this->styleIdSuffix('fp-b.png');
    $same = $this->styleIdSuffix('fp-c.png');

    $this->assertNotSame($first, $moved, 'A different position must produce a different id.');
    $this->assertSame($first, $same, 'The same position must produce the same id.');
  }

  /**
   * The hash suffix a crop variant's derivative URL carries, if any.
   *
   * Read off the generated URL rather than from the private helper: the
   * suffix only matters because it reaches the style id, and reflection
   * into getFocalPointHash() would assert the helper against itself.
   *
   * @param string $name
   *   The test image filename.
   *
   * @return string
   *   The 8-character suffix, or '' when the style id carries none.
   */
  private function styleIdSuffix(string $name): string {
    $image = [
      [
        'src' => '/sites/default/files/' . $name,
        'type' => 'image/png',
        'width' => 1,
        'height' => 1,
      ],
    ];

    $result = Resizer::resizer($image, [[100, 100, 768, 'crop']]);

    return preg_match('#/100-100-crop-([0-9a-f]{8})/#', $result[0]['src'], $m) === 1 ? $m[1] : '';
  }

}
