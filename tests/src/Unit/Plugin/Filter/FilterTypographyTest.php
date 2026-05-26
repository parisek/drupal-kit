<?php

namespace Drupal\Tests\custom_components\Unit\Plugin\Filter;

use Drupal\custom_components\Plugin\Filter\FilterTypography;
use Drupal\custom_components\Twig\TypographyExtension;
use Drupal\filter\FilterProcessResult;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\custom_components\Plugin\Filter\FilterTypography
 * @group custom_components
 */
class FilterTypographyTest extends TestCase {

  protected TypographyExtension $typography;

  protected FilterTypography $filter;

  protected function setUp(): void {
    parent::setUp();

    $this->typography = $this->createMock(TypographyExtension::class);
    // Default: pass text through unchanged so DOM-level assertions are
    // unaffected by typography rewriting.
    $this->typography->method('applyTypography')
      ->willReturnArgument(0);

    $this->filter = new FilterTypography(
      [],
      'filter_typography',
      ['provider' => 'custom_components'],
      $this->typography,
    );
  }

  /**
   * @covers ::process
   */
  public function testReturnsFilterProcessResult(): void {
    $result = $this->filter->process('<p>hi</p>', 'en');
    $this->assertInstanceOf(FilterProcessResult::class, $result);
  }

  /**
   * @covers ::process
   */
  public function testDelegatesToTypographyExtension(): void {
    $typography = $this->createMock(TypographyExtension::class);
    $typography->expects($this->once())
      ->method('applyTypography')
      ->with('<p>raw</p>')
      ->willReturn('<p>typographed</p>');

    $filter = new FilterTypography(
      [],
      'filter_typography',
      ['provider' => 'custom_components'],
      $typography,
    );

    $out = $filter->process('<p>raw</p>', 'en')->getProcessedText();
    $this->assertStringContainsString('typographed', $out);
  }

  /**
   * @covers ::process
   */
  public function testAddsBlockquoteClassToBlockquote(): void {
    $html = '<blockquote>Quoted.</blockquote>';
    $out = $this->filter->process($html, 'en')->getProcessedText();

    $this->assertStringContainsString('class="blockquote"', $out);
  }

  /**
   * @covers ::process
   */
  public function testDoesNotDuplicateBlockquoteClass(): void {
    $html = '<blockquote class="blockquote text-end">Q.</blockquote>';
    $out = $this->filter->process($html, 'en')->getProcessedText();

    $this->assertSame(1, preg_match_all('/class="[^"]*\bblockquote\b[^"]*"/', $out));
  }

  /**
   * @covers ::process
   */
  public function testPreservesExistingClassesAndAppendsBlockquote(): void {
    $html = '<blockquote class="text-muted">Q.</blockquote>';
    $out = $this->filter->process($html, 'en')->getProcessedText();

    $this->assertStringContainsString('text-muted', $out);
    $this->assertStringContainsString('blockquote', $out);
  }

  /**
   * @covers ::process
   */
  public function testNoOpWhenInputHasNoBlockquote(): void {
    $html = '<p>plain</p>';
    $out = $this->filter->process($html, 'en')->getProcessedText();

    $this->assertStringNotContainsString('blockquote', $out);
  }

}
