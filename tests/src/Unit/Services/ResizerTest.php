<?php

namespace Drupal\Tests\drupal_kit\Unit\Services;

use Drupal\drupal_kit\Services\Resizer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Resizer service.
 *
 * Note: Resizer uses static methods and \Drupal:: calls internally.
 * These tests cover input validation and SVG passthrough paths that
 * don't require a full Drupal bootstrap.
 *
 * @coversDefaultClass \Drupal\drupal_kit\Services\Resizer
 * @group drupal_kit
 */
class ResizerTest extends TestCase {

  /**
   * @covers ::resizer
   */
  public function testResizerEmptyImage(): void {
    $result = Resizer::resizer([], []);
    $this->assertSame([], $result);
  }

  /**
   * @covers ::resizer
   */
  public function testResizerNoSrc(): void {
    // Countable array without src: end() gives last value, then src check.
    $result = Resizer::resizer([['alt' => 'test']], []);
    $this->assertSame([], $result);
  }

  /**
   * @covers ::resizer
   */
  public function testResizerEmptySrc(): void {
    $result = Resizer::resizer([['src' => '']], []);
    $this->assertSame([], $result);
  }

  /**
   * @covers ::resizer
   */
  public function testResizerSvgPassthrough(): void {
    // Resizer expects array-of-arrays (as returned by getMediaField).
    $image = [
      [
        'src' => '/sites/default/files/icon.svg',
        'type' => 'image/svg+xml',
        'width' => 24,
        'height' => 24,
      ],
    ];
    $result = Resizer::resizer($image, [[100, 100, 0, 'default']]);
    $this->assertCount(1, $result);
    $this->assertSame('image/svg+xml', $result[0]['type']);
    $this->assertSame('/sites/default/files/icon.svg', $result[0]['src']);
  }

  /**
   * @covers ::resizer
   */
  public function testResizerCountableUsesLast(): void {
    $images = [
      ['src' => '/first.jpg', 'type' => 'image/jpeg'],
      ['src' => '/last.svg', 'type' => 'image/svg+xml', 'width' => 10, 'height' => 10],
    ];
    $result = Resizer::resizer($images, []);
    // Last image is SVG, should passthrough.
    $this->assertCount(1, $result);
    $this->assertSame('/last.svg', $result[0]['src']);
  }

  /**
   * @covers ::resizer
   */
  public function testResizerOriginalFallback(): void {
    // Non-SVG image with src outside /sites/default/files/ path.
    // Resizer can't process it, returns only original as fallback.
    $image = [
      [
        'src' => 'https://example.com/photo.jpg',
        'type' => 'image/jpeg',
        'width' => 800,
        'height' => 600,
        'alt' => 'Test',
      ],
    ];
    $result = Resizer::resizer($image, [[400, 300, 0, 'default']]);
    $this->assertCount(1, $result);
    $this->assertSame('https://example.com/photo.jpg', $result[0]['src']);
    $this->assertSame('image/jpeg', $result[0]['type']);
    $this->assertSame('Test', $result[0]['alt']);
  }

}
