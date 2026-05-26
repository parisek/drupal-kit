<?php

namespace Drupal\Tests\custom_components\Unit\Plugin\Filter;

use Drupal\custom_components\Plugin\Filter\FilterTable;
use Drupal\filter\FilterProcessResult;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\custom_components\Plugin\Filter\FilterTable
 * @group custom_components
 */
class FilterTableTest extends TestCase {

  protected FilterTable $filter;

  protected function setUp(): void {
    parent::setUp();
    $this->filter = new FilterTable([], 'filter_table', ['provider' => 'custom_components']);
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

    // The 'table' class should appear only once in the class list (the
    // word 'table' also appears inside 'table-responsive' which is fine).
    $this->assertSame(1, preg_match_all('/class="[^"]*\btable\b[^"]*"/', $out));
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
