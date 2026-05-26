<?php

namespace Drupal\Tests\custom_components\Unit\Plugin\Filter;

use Drupal\custom_components\Plugin\Filter\FilterYoutube;
use Drupal\filter\FilterProcessResult;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\custom_components\Plugin\Filter\FilterYoutube
 * @group custom_components
 */
class FilterYoutubeTest extends TestCase {

  protected FilterYoutube $filter;

  protected function setUp(): void {
    parent::setUp();
    $this->filter = new FilterYoutube([], 'filter_youtube', ['provider' => 'custom_components']);
  }

  /**
   * @covers ::process
   */
  public function testReturnsFilterProcessResult(): void {
    $result = $this->filter->process('<p>plain text</p>', 'en');
    $this->assertInstanceOf(FilterProcessResult::class, $result);
  }

  /**
   * @covers ::process
   */
  public function testEmbedsYoutubeWatchUrlAsIframe(): void {
    $html = '<p>https://www.youtube.com/watch?v=dQw4w9WgXcQ</p>';
    $out = $this->filter->process($html, 'en')->getProcessedText();

    $this->assertStringContainsString('<iframe', $out);
    $this->assertStringContainsString('youtube.com/embed/dQw4w9WgXcQ', $out);
    $this->assertStringContainsString('ratio-16x9', $out);
    $this->assertStringContainsString('loading="lazy"', $out);
  }

  /**
   * @covers ::process
   */
  public function testLeavesNonYoutubeParagraphUnchanged(): void {
    $html = '<p>https://example.com/article</p>';
    $out = $this->filter->process($html, 'en')->getProcessedText();

    $this->assertStringNotContainsString('<iframe', $out);
    $this->assertStringContainsString('example.com/article', $out);
  }

  /**
   * @covers ::process
   */
  public function testLeavesNonUrlParagraphUnchanged(): void {
    $html = '<p>Watch this: youtube.com/watch?v=foo</p>';
    $out = $this->filter->process($html, 'en')->getProcessedText();

    $this->assertStringNotContainsString('<iframe', $out);
    $this->assertStringContainsString('Watch this', $out);
  }

  /**
   * @covers ::process
   */
  public function testIgnoresYoutubeUrlWithoutVideoId(): void {
    // youtu.* domain but no ?v= parameter — extractor finds nothing.
    $html = '<p>https://youtu.be/dQw4w9WgXcQ</p>';
    $out = $this->filter->process($html, 'en')->getProcessedText();

    // Current implementation only matches ?v= style URLs, so this should
    // be left alone (documents current behavior).
    $this->assertStringNotContainsString('<iframe', $out);
  }

  /**
   * @covers ::process
   */
  public function testProcessesMultipleYoutubeParagraphs(): void {
    $html = '<p>https://www.youtube.com/watch?v=AAA</p><p>between</p><p>https://www.youtube.com/watch?v=BBB</p>';
    $out = $this->filter->process($html, 'en')->getProcessedText();

    $this->assertStringContainsString('youtube.com/embed/AAA', $out);
    $this->assertStringContainsString('youtube.com/embed/BBB', $out);
    $this->assertStringContainsString('between', $out);
  }

  /**
   * @covers ::process
   */
  public function testNoOpWhenInputHasNoParagraphs(): void {
    $html = '<div>https://www.youtube.com/watch?v=dQw4w9WgXcQ</div>';
    $out = $this->filter->process($html, 'en')->getProcessedText();

    // Only <p> elements are scanned; URL in <div> is ignored.
    $this->assertStringNotContainsString('<iframe', $out);
  }

}
