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

  /**
   * @covers ::filterLink
   *
   * REGRESSION. `page.front` is translatable. The first version read
   * `system.site` once and applied that one answer to every link, so a site
   * pointing each language at its own node lost the node that matched the
   * generation-time language and kept the other language's duplicate.
   */
  public function testPerLanguageFrontPagesAreJudgedSeparately(): void {
    $fronts = ['cs' => '/node/9', 'en' => '/node/10'];

    $czech_front = [
      'url' => 'https://example.com/',
      'langcode' => 'cs',
      'meta' => ['path' => 'node/9'],
    ];
    $english_ordinary = [
      'url' => 'https://example.com/en/node/9',
      'langcode' => 'en',
      'meta' => ['path' => 'node/9'],
    ];

    $this->assertNull(
      FrontPageSitemapFilter::filterLink($czech_front, $fronts),
      'node/9 is the Czech front page, so the Czech entry is a duplicate',
    );
    $this->assertSame(
      $english_ordinary,
      FrontPageSitemapFilter::filterLink($english_ordinary, $fronts),
      'the same node is an ordinary page in English and must survive',
    );
  }

  /**
   * @covers ::filterLink
   *
   * A surviving link must not re-advertise the withheld URL through its
   * hreflang block — pruning the alternate is the whole point of doing this
   * per language rather than per link.
   */
  public function testAlternateUrlsLoseOnlyTheFrontPageLanguage(): void {
    $link = [
      'url' => 'https://example.com/en/node/9',
      'langcode' => 'en',
      'meta' => ['path' => 'node/9'],
      'alternate_urls' => [
        'cs' => 'https://example.com/node/9',
        'en' => 'https://example.com/en/node/9',
      ],
    ];

    $filtered = FrontPageSitemapFilter::filterLink($link, ['cs' => '/node/9', 'en' => '/node/10']);

    $this->assertNotNull($filtered);
    $this->assertSame(
      ['en' => 'https://example.com/en/node/9'],
      $filtered['alternate_urls'],
    );
  }

  /**
   * @covers ::filterLink
   *
   * A link simple_sitemap did not attribute to a language — the monolingual
   * shape — is judged by any match at all, which is what the pre-multilingual
   * behaviour did and what a single-language site still needs.
   */
  public function testLinkWithoutLangcodeIsDroppedOnAnyMatch(): void {
    $this->assertNull(FrontPageSitemapFilter::filterLink(
      ['url' => 'https://example.com/node/9', 'meta' => ['path' => 'node/9']],
      ['cs' => '/node/9'],
    ));
  }

  /**
   * @covers ::filterLink
   *
   * A link carrying no path is not a duplicate of anything; it is returned
   * untouched rather than guessed at.
   */
  public function testLinkWithoutPathIsUntouched(): void {
    $link = ['url' => 'https://example.com/somewhere'];

    $this->assertSame(
      $link,
      FrontPageSitemapFilter::filterLink($link, ['cs' => '/node/9']),
    );
  }

}
