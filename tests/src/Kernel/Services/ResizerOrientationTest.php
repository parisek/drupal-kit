<?php

namespace Drupal\Tests\drupal_kit\Kernel\Services;

use Drupal\Tests\drupal_kit\Kernel\ResizerKernelTestBase;
use Drupal\drupal_kit\Services\Resizer;

/**
 * Kernel tests for the orientation-aware call shape of the resizer.
 *
 * The tests read the media attribute of the produced sources rather than
 * pixel sizes: the breakpoint in each tuple identifies which bucket was
 * chosen, and it survives whether or not the image toolkit produced a
 * derivative. Asserting on widths would make the test depend on GD.
 *
 * @coversDefaultClass \Drupal\drupal_kit\Services\Resizer
 * @group drupal_kit
 */
class ResizerOrientationTest extends ResizerKernelTestBase {

  /**
   * An orientation map with one identifying breakpoint per bucket.
   *
   * @return array<string, array<int, array<int, mixed>>>
   *   The map.
   */
  private function map(): array {
    return [
      'landscape' => [[100, 50, 1100, 'default']],
      'portrait' => [[50, 100, 1200, 'default']],
      'square' => [[80, 80, 1300, 'default']],
    ];
  }

  /**
   * Build an image array with the given dimensions.
   *
   * Width and height are deliberately not typed as int: Drupal media
   * metadata is not always an integer, and the classification has to read
   * what it is actually given.
   *
   * @return array<int, array<string, mixed>>
   *   The image, in the array-of-images shape callers use.
   */
  private function image(int|float|string $width, int|float|string $height, string $name = 'o.png'): array {
    $this->createTestPngFile($name);

    return [[
      'src' => '/sites/default/files/' . $name,
      'type' => 'image/png',
      'width' => $width,
      'height' => $height,
    ],
    ];
  }

  /**
   * The breakpoints of every media-carrying source.
   *
   * @param array<int, array<string, mixed>> $result
   *   Resizer output.
   *
   * @return array<int, string>
   *   Media attributes, fallback excluded.
   */
  private function breakpoints(array $result): array {
    $media = [];
    foreach ($result as $entry) {
      if (!empty($entry['media'])) {
        $media[] = $entry['media'];
      }
    }

    return $media;
  }

  /**
   * @covers ::resizer
   * @covers ::selectVariants
   * @covers ::asOrientationMap
   * @covers ::classifyAspect
   */
  public function testLandscapeImagePicksTheLandscapeBucket(): void {
    $result = Resizer::resizer($this->image(1200, 600, 'l.png'), [$this->map()]);

    $this->assertSame(['(min-width: 1100px)'], $this->breakpoints($result));
  }

  /**
   * @covers ::resizer
   * @covers ::classifyAspect
   */
  public function testPortraitImagePicksThePortraitBucket(): void {
    $result = Resizer::resizer($this->image(600, 1200, 'p.png'), [$this->map()]);

    $this->assertSame(['(min-width: 1200px)'], $this->breakpoints($result));
  }

  /**
   * @covers ::resizer
   * @covers ::classifyAspect
   */
  public function testSquareImagePicksTheSquareBucket(): void {
    $result = Resizer::resizer($this->image(800, 800, 's.png'), [$this->map()]);

    $this->assertSame(['(min-width: 1300px)'], $this->breakpoints($result));
  }

  /**
   * Both edges of the tolerance band count as square.
   *
   * 1000x900 and 900x1000 sit exactly 10 % from square, measured against
   * the longer side. Inclusive on both sides, so both are square.
   *
   * @covers ::classifyAspect
   */
  public function testTheToleranceBandIsInclusiveOnBothEdges(): void {
    $wide = Resizer::resizer($this->image(1000, 900, 'bw.png'), [$this->map()]);
    $tall = Resizer::resizer($this->image(900, 1000, 'bt.png'), [$this->map()]);

    $this->assertSame(['(min-width: 1300px)'], $this->breakpoints($wide));
    $this->assertSame(['(min-width: 1300px)'], $this->breakpoints($tall));
  }

  /**
   * One pixel past the band is no longer square.
   *
   * @covers ::classifyAspect
   */
  public function testJustOutsideTheBandIsNotSquare(): void {
    $result = Resizer::resizer($this->image(1000, 899, 'ob.png'), [$this->map()]);

    $this->assertSame(['(min-width: 1100px)'], $this->breakpoints($result));
  }

  /**
   * An image with no dimensions falls back to landscape.
   *
   * This is the ordinary case, not an edge case: MediaArrayBuilder fills
   * width and height only when the file exists and is a valid image, so a
   * remote or missing file arrives with neither key.
   *
   * @covers ::classifyAspect
   */
  public function testMissingDimensionsFallBackToLandscape(): void {
    $this->createTestPngFile('nodims.png');
    $image = [[
      'src' => '/sites/default/files/nodims.png',
      'type' => 'image/png',
    ],
    ];

    $result = Resizer::resizer($image, [$this->map()]);

    $this->assertSame(['(min-width: 1100px)'], $this->breakpoints($result));
  }

  /**
   * A zero dimension falls back to landscape too.
   *
   * @covers ::classifyAspect
   */
  public function testZeroDimensionsFallBackToLandscape(): void {
    $result = Resizer::resizer($this->image(0, 0, 'zero.png'), [$this->map()]);

    $this->assertSame(['(min-width: 1100px)'], $this->breakpoints($result));
  }

  /**
   * A matched but empty bucket falls through to landscape.
   *
   * @covers ::selectVariants
   */
  public function testEmptyBucketFallsThroughToLandscape(): void {
    $map = [
      'landscape' => [[100, 50, 1100, 'default']],
      'portrait' => [],
    ];

    $result = Resizer::resizer($this->image(600, 1200, 'eb.png'), [$map]);

    $this->assertSame(['(min-width: 1100px)'], $this->breakpoints($result));
  }

  /**
   * An absent bucket falls through to landscape as well.
   *
   * A map may therefore carry only the orientations that actually differ.
   *
   * @covers ::selectVariants
   */
  public function testAbsentBucketFallsThroughToLandscape(): void {
    $map = ['landscape' => [[100, 50, 1100, 'default']]];

    $result = Resizer::resizer($this->image(800, 800, 'ab.png'), [$map]);

    $this->assertSame(['(min-width: 1100px)'], $this->breakpoints($result));
  }

  /**
   * Positional tuples still behave exactly as before.
   *
   * The detection reads the argument shape, so a caller that never writes
   * a map never meets the new code path.
   *
   * @covers ::asOrientationMap
   */
  public function testPositionalTuplesAreUnaffected(): void {
    $image = $this->image(1200, 600, 'tuples.png');

    $result = Resizer::resizer($image, [[100, 50, 900, 'default'], [50, 25, 0, 'default']]);

    $this->assertSame(['(min-width: 900px)'], $this->breakpoints($result));
  }

  /**
   * A single positional tuple is not mistaken for a map.
   *
   * It is one argument and an array, which is the shape the map detection
   * looks at; only the orientation keys tell the two apart.
   *
   * @covers ::asOrientationMap
   */
  public function testSinglePositionalTupleStaysOnTheTuplePath(): void {
    $image = $this->image(1200, 600, 'single.png');

    $result = Resizer::resizer($image, [[100, 50, 900, 'default']]);

    $this->assertSame(['(min-width: 900px)'], $this->breakpoints($result));
  }

  /**
   * A direct PHP call may pass the map unwrapped.
   *
   * The Twig filter collects arguments variadically, so the map arrives
   * inside a one-element list; README tells PHP callers to call
   * Resizer::resizer() directly, where it does not.
   *
   * @covers ::asOrientationMap
   */
  public function testTheMapMayArriveUnwrapped(): void {
    $result = Resizer::resizer($this->image(600, 1200, 'uw.png'), $this->map());

    $this->assertSame(['(min-width: 1200px)'], $this->breakpoints($result));
  }

  /**
   * A fractional pair is judged on its own numbers, not on truncated ones.
   *
   * 1000.9 x 900.1 differ by 100.8 where the band allows 100.09, so the
   * image is landscape. Truncating both to int first makes it 1000 x 900,
   * which is exactly on the band and therefore square.
   *
   * @covers ::classifyAspect
   */
  public function testFractionalDimensionsAreNotTruncated(): void {
    $result = Resizer::resizer($this->image(1000.9, 900.1, 'fr.png'), [$this->map()]);

    $this->assertSame(['(min-width: 1100px)'], $this->breakpoints($result));
  }

  /**
   * Dimensions past PHP_INT_MAX keep their ratio.
   *
   * Two sides in a 2:1 ratio both saturate to PHP_INT_MAX under an int
   * cast, which reads as square.
   *
   * @covers ::classifyAspect
   */
  public function testHugeDimensionsKeepTheirRatio(): void {
    $image = $this->image('100000000000000000000', '50000000000000000000', 'huge.png');

    $result = Resizer::resizer($image, [$this->map()]);

    $this->assertSame(['(min-width: 1100px)'], $this->breakpoints($result));
  }

  /**
   * A tuple keyed by an orientation name is still a tuple.
   *
   * The old code iterated every entry, so a caller could label positional
   * tuples with names of its own. Reading the key alone would send such a
   * list down the map path and explode one tuple into four.
   *
   * @covers ::asOrientationMap
   */
  public function testTupleListKeyedByOrientationNameStaysPositional(): void {
    $variants = [
      'landscape' => [100, 50, 900, 'default'],
      'thumbnail' => [50, 25, 0, 'default'],
    ];

    $result = Resizer::resizer($this->image(1200, 600, 'named.png'), $variants);

    $this->assertSame(['(min-width: 900px)'], $this->breakpoints($result));
  }

  /**
   * A bucket that is not a list of tuples yields no variants, and no error.
   *
   * @covers ::selectVariants
   */
  public function testScalarBucketProducesOnlyTheFallback(): void {
    $map = ['landscape' => [[100, 50, 1100, 'default']], 'portrait' => 'nonsense'];

    $result = Resizer::resizer($this->image(600, 1200, 'scalar.png'), [$map]);

    // 'nonsense' is not empty, so it does not fall through to landscape;
    // it selects nothing and the original is the only source left.
    $this->assertSame([], $this->breakpoints($result));
    $this->assertSame('/sites/default/files/scalar.png', end($result)['src']);
  }

  /**
   * The positional path keeps its fallback and its zero-breakpoint tuple.
   *
   * The narrower assertion elsewhere — one media string — would still pass
   * if the fallback were dropped or the medialess tuple lost.
   *
   * @covers ::resizer
   */
  public function testPositionalOutputKeepsFallbackAndMedialessTuple(): void {
    $image = $this->image(1200, 600, 'shape.png');

    $result = Resizer::resizer($image, [[100, 50, 900, 'default'], [50, 25, 0, 'default']]);

    // Whatever the toolkit does with the derivatives, the last entry is the
    // original and at least one source carries no media.
    $this->assertSame('/sites/default/files/shape.png', end($result)['src']);
    $medialess = array_filter($result, static fn(array $entry): bool => empty($entry['media']));
    $this->assertNotEmpty($medialess);
  }

}
