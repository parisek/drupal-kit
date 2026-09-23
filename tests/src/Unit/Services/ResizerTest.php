<?php

namespace Drupal\Tests\drupal_kit\Unit\Services;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\ImageToolkit\ImageToolkitManager;
use Drupal\drupal_kit\Services\Resizer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Resizer service.
 *
 * These cover input validation and the SVG passthrough, which need no
 * Drupal bootstrap.
 *
 * They build the service rather than calling the static
 * Resizer::resizer() facade, and that is a deliberate consequence of
 * #150. The facade fetches the service from the container before the
 * early returns run, so the static entry point now needs a container
 * even for an empty image, where it used to answer on its own.
 *
 * Production always has a container, so nothing real changes. A unit
 * test is precisely the caller that does not, and building the object
 * is what a unit test should have been doing anyway.
 *
 * @coversDefaultClass \Drupal\drupal_kit\Services\Resizer
 * @group drupal_kit
 */
class ResizerTest extends TestCase {

  /**
   * A Resizer with every dependency mocked.
   *
   * None of the cases in this file reaches a dependency: they return on
   * the input guards or on the SVG passthrough. The mocks exist to let the
   * constructor run, and a case that started touching one would fail
   * loudly rather than quietly reach a real service.
   */
  private function resizer(): Resizer {
    return new Resizer(
      $this->createMock(ImageToolkitManager::class),
      $this->createMock(ModuleHandlerInterface::class),
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(ConfigFactoryInterface::class),
    );
  }

  /**
   * @covers ::resize
   */
  public function testResizerEmptyImage(): void {
    $result = $this->resizer()->resize([], []);
    $this->assertSame([], $result);
  }

  /**
   * @covers ::resize
   */
  public function testResizerNoSrc(): void {
    // Countable array without src: end() gives last value, then src check.
    $result = $this->resizer()->resize([['alt' => 'test']], []);
    $this->assertSame([], $result);
  }

  /**
   * @covers ::resize
   */
  public function testResizerEmptySrc(): void {
    $result = $this->resizer()->resize([['src' => '']], []);
    $this->assertSame([], $result);
  }

  /**
   * @covers ::resize
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
    $result = $this->resizer()->resize($image, [[100, 100, 0, 'default']]);
    $this->assertCount(1, $result);
    $this->assertSame('image/svg+xml', $result[0]['type']);
    $this->assertSame('/sites/default/files/icon.svg', $result[0]['src']);
  }

  /**
   * @covers ::resize
   */
  public function testResizerCountableUsesLast(): void {
    $images = [
      ['src' => '/first.jpg', 'type' => 'image/jpeg'],
      ['src' => '/last.svg', 'type' => 'image/svg+xml', 'width' => 10, 'height' => 10],
    ];
    $result = $this->resizer()->resize($images, []);
    // Last image is SVG, should passthrough.
    $this->assertCount(1, $result);
    $this->assertSame('/last.svg', $result[0]['src']);
  }

  /**
   * @covers ::resize
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
    $result = $this->resizer()->resize($image, [[400, 300, 0, 'default']]);
    $this->assertCount(1, $result);
    $this->assertSame('https://example.com/photo.jpg', $result[0]['src']);
    $this->assertSame('image/jpeg', $result[0]['type']);
    $this->assertSame('Test', $result[0]['alt']);
  }

}
