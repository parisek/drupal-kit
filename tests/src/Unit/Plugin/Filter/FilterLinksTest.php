<?php

namespace Drupal\Tests\custom_components\Unit\Plugin\Filter;

use Drupal\custom_components\Plugin\Filter\FilterLinks;
use Drupal\filter\FilterProcessResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @coversDefaultClass \Drupal\custom_components\Plugin\Filter\FilterLinks
 * @group custom_components
 */
class FilterLinksTest extends TestCase {

  protected FilterLinks $filter;

  protected function setUp(): void {
    parent::setUp();

    $request = Request::create('https://example.com/', 'GET');
    $stack = new RequestStack();
    $stack->push($request);

    $this->filter = new FilterLinks(
      [],
      'filter_links',
      ['provider' => 'custom_components'],
      $stack,
    );
  }

  /**
   * @covers ::process
   */
  public function testReturnsFilterProcessResult(): void {
    $result = $this->filter->process('<p>nolinks</p>', 'en');
    $this->assertInstanceOf(FilterProcessResult::class, $result);
  }

  /**
   * @covers ::process
   */
  public function testExternalLinkGetsTargetBlank(): void {
    $html = '<a href="https://other.com/page">link</a>';
    $out = $this->filter->process($html, 'en')->getProcessedText();

    $this->assertStringContainsString('target="_blank"', $out);
  }

  /**
   * @covers ::process
   */
  public function testInternalLinkHasNoTarget(): void {
    $html = '<a href="https://example.com/about" target="_blank">about</a>';
    $out = $this->filter->process($html, 'en')->getProcessedText();

    $this->assertStringNotContainsString(' target=', $out);
  }

  /**
   * @covers ::process
   */
  public function testRelativeLinkHasNoTarget(): void {
    $html = '<a href="/contact" target="_blank">contact</a>';
    $out = $this->filter->process($html, 'en')->getProcessedText();

    $this->assertStringNotContainsString(' target=', $out);
  }

  /**
   * @covers ::process
   */
  public function testPdfLinkAlwaysGetsTargetBlank(): void {
    // Internal PDF — should still open in a new tab.
    $html = '<a href="/files/brochure.pdf">PDF</a>';
    $out = $this->filter->process($html, 'en')->getProcessedText();

    $this->assertStringContainsString('target="_blank"', $out);
  }

  /**
   * @covers ::process
   */
  public function testNoOpWhenNoLinks(): void {
    $html = '<p>Just text</p>';
    $out = $this->filter->process($html, 'en')->getProcessedText();

    $this->assertStringNotContainsString(' target=', $out);
    $this->assertStringContainsString('Just text', $out);
  }

  /**
   * @covers ::create
   * @covers ::__construct
   *
   * Verifies the factory wires the request_stack service into the
   * plugin constructor.
   */
  public function testCreateFactoryPullsRequestStackFromContainer(): void {
    $stack = new RequestStack();
    $stack->push(Request::create('https://factory.test/', 'GET'));

    $container = $this->createMock(\Symfony\Component\DependencyInjection\ContainerInterface::class);
    $container->expects($this->once())
      ->method('get')
      ->with('request_stack')
      ->willReturn($stack);

    $filter = FilterLinks::create($container, [], 'filter_links', ['provider' => 'custom_components']);
    $this->assertInstanceOf(FilterLinks::class, $filter);

    // Smoke test: the injected stack is what the filter uses for host
    // comparison. A link on factory.test must be classified internal.
    $out = $filter->process('<a href="https://factory.test/x" target="_blank">x</a>', 'en')
      ->getProcessedText();
    $this->assertStringNotContainsString(' target=', $out);
  }

}
