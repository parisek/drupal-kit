<?php

namespace Drupal\Tests\custom_components\Unit\Plugin\Filter;

use Drupal\Core\DependencyInjection\ContainerBuilder;
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

    $container = new ContainerBuilder();
    $container->set('request_stack', $stack);
    \Drupal::setContainer($container);

    $this->filter = new FilterLinks([], 'filter_links', ['provider' => 'custom_components']);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Drop the container to clean state to avoid leaking the
    // request_stack into tests that run after this one.
    if (\Drupal::hasContainer()) {
      \Drupal::unsetContainer();
    }
    parent::tearDown();
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

    $this->assertStringNotContainsString('target=', $out);
  }

  /**
   * @covers ::process
   */
  public function testRelativeLinkHasNoTarget(): void {
    $html = '<a href="/contact" target="_blank">contact</a>';
    $out = $this->filter->process($html, 'en')->getProcessedText();

    $this->assertStringNotContainsString('target=', $out);
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

    $this->assertStringNotContainsString('target=', $out);
    $this->assertStringContainsString('Just text', $out);
  }

}
