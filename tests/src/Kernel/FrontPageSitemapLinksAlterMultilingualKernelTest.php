<?php

namespace Drupal\Tests\drupal_kit\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;

/**
 * The multilingual branch of drupal_kit_simple_sitemap_links_alter().
 *
 * FrontPageSitemapLinksAlterKernelTest installs only drupal_kit and system,
 * so every one of its cases runs the monolingual else-branch. The branch that
 * reads a per-language `page.front` override had no coverage at all — which is
 * how it came to call getLanguageConfigOverride() on LanguageManagerInterface,
 * a method that only exists on ConfigurableLanguageManagerInterface. Nothing
 * executed the line, so nothing failed; static analysis found it instead.
 *
 * Every project this library serves is multilingual, so this is the branch
 * that actually runs in production.
 *
 * @group drupal_kit
 */
class FrontPageSitemapLinksAlterMultilingualKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['drupal_kit', 'system', 'language'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'language']);

    // The stored value, which every language without an override inherits.
    \Drupal::configFactory()
      ->getEditable('system.site')
      ->set('page.front', '/node/9')
      ->save();

    ConfigurableLanguage::createFromLangcode('cs')->save();

    // Czech points its front page somewhere else. This is the shape the hook
    // exists for: one site, one sitemap, two different front pages.
    \Drupal::languageManager()
      ->getLanguageConfigOverride('cs', 'system.site')
      ->set('page.front', '/node/42')
      ->save();
  }

  /**
   * Build the link shape simple_sitemap hands to the hook.
   *
   * @param string $path
   *   The internal path in the link's meta array.
   * @param string|null $langcode
   *   The language simple_sitemap attributed the link to, if any.
   * @param array<string, string> $alternates
   *   Alternate URLs, keyed by langcode.
   */
  private function link(string $path, ?string $langcode = NULL, array $alternates = []): array {
    $link = ['url' => 'https://example.com/' . ltrim($path, '/'), 'meta' => ['path' => $path]];
    if ($langcode !== NULL) {
      $link['langcode'] = $langcode;
    }
    if ($alternates !== []) {
      $link['alternate_urls'] = $alternates;
    }

    return $link;
  }

  /**
   * Each language drops its own front page and keeps the other's.
   *
   * The whole point of reading the overrides: /node/42 is the front page in
   * Czech and an ordinary page in English, so the same path must survive in
   * one language and vanish in the other.
   */
  public function testEachLanguageDropsItsOwnFrontPage(): void {
    $links = [
      'en-front' => $this->link('node/9', 'en'),
      'en-other' => $this->link('node/42', 'en'),
      'cs-front' => $this->link('node/42', 'cs'),
      'cs-other' => $this->link('node/9', 'cs'),
    ];

    $this->alterLinks($links);

    $this->assertSame(['en-other', 'cs-other'], array_keys($links));
  }

  /**
   * A language with no override inherits the stored front page.
   *
   * English sets none, so it falls back to system.site's /node/9 — the
   * `?? $stored` in the hook, which nothing exercised before.
   */
  public function testLanguageWithoutAnOverrideUsesTheStoredValue(): void {
    $links = ['a' => $this->link('node/9', 'en'), 'b' => $this->link('about', 'en')];

    $this->alterLinks($links);

    $this->assertSame(['b'], array_keys($links));
  }

  /**
   * A link with no langcode is dropped when any language matches.
   *
   * A link simple_sitemap did not attribute to a language speaks for the
   * whole site, so a match anywhere removes it.
   */
  public function testUnattributedLinkIsDroppedOnAnyMatch(): void {
    $links = ['a' => $this->link('node/42'), 'b' => $this->link('contact')];

    $this->alterLinks($links);

    $this->assertSame(['b'], array_keys($links));
  }

  /**
   * A surviving link loses only the alternates that point at a front page.
   *
   * The English entry for /node/42 stays, but its Czech alternate would send
   * a crawler to the Czech front page by its node path — the duplicate this
   * hook exists to remove.
   */
  public function testMatchingAlternatesAreStrippedFromSurvivingLink(): void {
    $links = [
      'a' => $this->link('node/42', 'en', [
        'en' => 'https://example.com/node/42',
        'cs' => 'https://example.com/cs/node/42',
      ]),
    ];

    $this->alterLinks($links);

    $this->assertSame(['a'], array_keys($links));
    $this->assertSame(['en' => 'https://example.com/node/42'], $links['a']['alternate_urls']);
  }

  /**
   * Run the hook the way core runs it.
   *
   * Through the module handler rather than by calling a function: the
   * implementation is a #[Hook] class now, so invoking it directly would
   * test a method while production tests the registration. The registration
   * is the half that a missing attribute or a renamed method breaks.
   *
   * @param array<string, mixed> $links
   *   The sitemap links, altered in place.
   */
  private function alterLinks(array &$links): void {
    $this->container->get('module_handler')
      ->invoke('drupal_kit', 'simple_sitemap_links_alter', [&$links, new \stdClass()]);
  }

}
