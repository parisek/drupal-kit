<?php

namespace Drupal\Tests\drupal_kit\Unit\Plugin\Filter;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\drupal_kit\Plugin\Filter\FilterTypography;
use Drupal\drupal_kit\Twig\TypographyExtension;
use Drupal\filter\FilterProcessResult;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\drupal_kit\Plugin\Filter\FilterTypography
 * @group drupal_kit
 */
class FilterTypographyTest extends TestCase {

  /**
   * Mocked typography extension, stubbed to pass text through unchanged.
   *
   * @var \Drupal\drupal_kit\Twig\TypographyExtension
   */
  protected TypographyExtension $typography;

  /**
   * The filter plugin instance under test.
   *
   * @var \Drupal\drupal_kit\Plugin\Filter\FilterTypography
   */
  protected FilterTypography $filter;

  /**
   * Instantiates the filter plugin under test with a mocked typography service.
   */
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
      ['provider' => 'drupal_kit'],
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
      ['provider' => 'drupal_kit'],
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

  /**
   * @covers ::create
   * @covers ::__construct
   *
   * The container factory pulls the typography_twig_extension service
   * and forwards the plugin args to the constructor.
   */
  public function testCreatePullsTypographyServiceFromContainer(): void {
    $typography = $this->createMock(TypographyExtension::class);
    $container = $this->createMock(ContainerInterface::class);
    $container->expects($this->once())
      ->method('get')
      ->with('drupal_kit.typography_twig_extension')
      ->willReturn($typography);

    $instance = FilterTypography::create($container, [], 'filter_typography_plugin', ['provider' => 'drupal_kit']);

    $this->assertInstanceOf(FilterTypography::class, $instance);
  }

}
