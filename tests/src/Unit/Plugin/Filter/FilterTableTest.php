<?php

namespace Drupal\Tests\drupal_kit\Unit\Plugin\Filter;

use Drupal\drupal_kit\Plugin\Filter\FilterTable;
use Drupal\filter\FilterProcessResult;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\drupal_kit\Plugin\Filter\FilterTable
 * @group drupal_kit
 */
class FilterTableTest extends TestCase {

  /**
   * The filter plugin instance under test.
   *
   * @var \Drupal\drupal_kit\Plugin\Filter\FilterTable
   */
  protected FilterTable $filter;

  /**
   * Instantiates the filter plugin under test.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->filter = new FilterTable([], 'filter_table', ['provider' => 'drupal_kit']);
  }

  /**
   * @covers ::process
   */
  public function testReturnsFilterProcessResult(): void {
    $result = $this->filter->process('<p>no tables</p>', 'en');
    $this->assertInstanceOf(FilterProcessResult::class, $result);
  }

  /**
   * @covers ::process
   */
  public function testWrapsTableInResponsiveDivAndAddsTableClass(): void {
    $html = '<table><tr><td>cell</td></tr></table>';
    $out = $this->filter->process($html, 'en')->getProcessedText();

    $this->assertStringContainsString('table-responsive', $out);
    $this->assertStringContainsString('class="table"', $out);
    // The responsive wrapper must precede the table.
    $this->assertLessThan(
      strpos($out, '<table'),
      strpos($out, 'table-responsive'),
      'Responsive wrapper div must come before the <table> tag.',
    );
  }

  /**
   * @covers ::process
   */
  public function testPreservesExistingClassesAndAppendsTable(): void {
    $html = '<table class="data sortable"><tr><td>x</td></tr></table>';
    $out = $this->filter->process($html, 'en')->getProcessedText();

    $this->assertStringContainsString('data', $out);
    $this->assertStringContainsString('sortable', $out);
    $this->assertStringContainsString('table', $out);
  }

  /**
   * @covers ::process
   */
  public function testDoesNotDuplicateTableClass(): void {
    $html = '<table class="table striped"><tr><td>x</td></tr></table>';
    $out = $this->filter->process($html, 'en')->getProcessedText();

    // No duplicate "table" inside the same class list. The wrapper div
    // intentionally uses "table-responsive", which is distinct.
    $this->assertStringNotContainsString('class="table table"', $out);
    $this->assertStringNotContainsString('class="table table ', $out);
    $this->assertStringNotContainsString(' table table"', $out);
    $this->assertStringContainsString('striped', $out);
  }

  /**
   * @covers ::process
   */
  public function testNoOpWhenInputHasNoTables(): void {
    $html = '<p>Hello <em>world</em></p>';
    $out = $this->filter->process($html, 'en')->getProcessedText();

    $this->assertStringNotContainsString('table-responsive', $out);
    $this->assertStringContainsString('Hello', $out);
  }

  /**
   * @covers ::process
   */
  public function testWrapsMultipleTablesEach(): void {
    $html = '<table><tr><td>a</td></tr></table><p>x</p><table><tr><td>b</td></tr></table>';
    $out = $this->filter->process($html, 'en')->getProcessedText();

    $this->assertSame(2, substr_count($out, 'table-responsive'));
  }

}
