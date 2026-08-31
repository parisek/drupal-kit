<?php

namespace Drupal\Tests\drupal_kit\Unit\Services;

use Drupal\drupal_kit\Services\FrontPageSitemapFilter;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the FrontPageSitemapFilter service.
 *
 * @coversDefaultClass \Drupal\drupal_kit\Services\FrontPageSitemapFilter
 * @group drupal_kit
 */
class FrontPageSitemapFilterTest extends TestCase {

  /**
   * The node behind the front page duplicates "/".
   *
   * Simple_sitemap lists the path without a leading slash, system.site stores
   * it with one, so the two have to be normalised before comparing.
   *
   * @covers ::isFrontDuplicate
   */
  public function testFrontNodePathIsDuplicate(): void {
    $this->assertTrue(FrontPageSitemapFilter::isFrontDuplicate('node/9', '/node/9'));
    $this->assertTrue(FrontPageSitemapFilter::isFrontDuplicate('/node/9', '/node/9'));
  }

  /**
   * Any other node keeps its place in the sitemap.
   *
   * The `node/1` against `/node/10` pair guards against a prefix match: these
   * are different pages and a `str_starts_with()` style comparison would
   * silently drop the wrong one.
   *
   * @covers ::isFrontDuplicate
   */
  public function testOtherPathsAreKept(): void {
    $this->assertFalse(FrontPageSitemapFilter::isFrontDuplicate('node/10', '/node/9'));
    $this->assertFalse(FrontPageSitemapFilter::isFrontDuplicate('node/1', '/node/10'));
    $this->assertFalse(FrontPageSitemapFilter::isFrontDuplicate('', '/node/9'));
  }

  /**
   * The front page itself stays: that entry is the canonical one.
   *
   * @covers ::isFrontDuplicate
   */
  public function testRootPathIsKept(): void {
    $this->assertFalse(FrontPageSitemapFilter::isFrontDuplicate('/', '/node/9'));
  }

  /**
   * A site whose front page is "/" has no node duplicate to remove.
   *
   * @covers ::isFrontDuplicate
   */
  public function testNoFrontNodeConfigured(): void {
    $this->assertFalse(FrontPageSitemapFilter::isFrontDuplicate('node/9', '/'));
    $this->assertFalse(FrontPageSitemapFilter::isFrontDuplicate('node/9', ''));
  }

  /**
   * Surrounding whitespace in either value is normalised away.
   *
   * @covers ::isFrontDuplicate
   */
  public function testWhitespaceIsNormalised(): void {
    $this->assertTrue(FrontPageSitemapFilter::isFrontDuplicate('  node/9 ', " /node/9\n"));
  }

}
