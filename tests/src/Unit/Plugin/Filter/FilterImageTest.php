<?php

namespace Drupal\Tests\drupal_kit\Unit\Plugin\Filter;

use Drupal\drupal_kit\Plugin\Filter\FilterImage;
use Drupal\filter\FilterProcessResult;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\drupal_kit\Plugin\Filter\FilterImage
 * @group drupal_kit
 */
class FilterImageTest extends TestCase {

  /**
   * The filter plugin instance under test.
   *
   * @var \Drupal\drupal_kit\Plugin\Filter\FilterImage
   */
  protected FilterImage $filter;

  /**
   * Instantiates the filter plugin under test.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->filter = new FilterImage([], 'filter_image', ['provider' => 'drupal_kit']);
  }

  /**
   * @covers ::process
   */
  public function testReturnsFilterProcessResult(): void {
    $result = $this->filter->process('<p>no images</p>', 'en');
    $this->assertInstanceOf(FilterProcessResult::class, $result);
  }

  /**
   * @covers ::process
   */
  public function testAddsImgFluidClassAndLazyLoading(): void {
    $html = '<img src="/foo.jpg" alt="Foo">';
    $out = $this->filter->process($html, 'en')->getProcessedText();

    $this->assertStringContainsString('class="img-fluid"', $out);
    $this->assertStringContainsString('loading="lazy"', $out);
  }

  /**
   * @covers ::process
   */
  public function testPreservesExistingClassesAndAppendsImgFluid(): void {
    $html = '<img src="/foo.jpg" class="rounded shadow" alt="Foo">';
    $out = $this->filter->process($html, 'en')->getProcessedText();

    $this->assertStringContainsString('rounded', $out);
    $this->assertStringContainsString('shadow', $out);
    $this->assertStringContainsString('img-fluid', $out);
  }

  /**
   * @covers ::process
   */
  public function testDoesNotDuplicateImgFluidClass(): void {
    $html = '<img src="/foo.jpg" class="img-fluid rounded" alt="Foo">';
    $out = $this->filter->process($html, 'en')->getProcessedText();

    // img-fluid should appear exactly once in the class attribute.
    $this->assertSame(1, substr_count($out, 'img-fluid'));
  }

  /**
   * @covers ::process
   */
  public function testNoOpWhenInputHasNoImages(): void {
    $html = '<p>Hello <strong>world</strong></p>';
    $out = $this->filter->process($html, 'en')->getProcessedText();

    $this->assertStringNotContainsString('img-fluid', $out);
    $this->assertStringNotContainsString('loading=', $out);
    $this->assertStringContainsString('Hello', $out);
  }

  /**
   * @covers ::process
   */
  public function testProcessesMultipleImagesIndependently(): void {
    $html = '<img src="/a.jpg" alt="A"><img src="/b.jpg" class="custom" alt="B">';
    $out = $this->filter->process($html, 'en')->getProcessedText();

    $this->assertSame(2, substr_count($out, 'img-fluid'));
    $this->assertSame(2, substr_count($out, 'loading="lazy"'));
    $this->assertStringContainsString('custom', $out);
  }

}
