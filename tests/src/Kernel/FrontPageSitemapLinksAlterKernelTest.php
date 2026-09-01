<?php

namespace Drupal\Tests\drupal_kit\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Kernel-level tests for drupal_kit_simple_sitemap_links_alter().
 *
 * The pure comparison is covered by FrontPageSitemapFilterTest. What only a
 * kernel test can exercise is the wiring around it: reading page.front out of
 * system.site, digging the path out of each link's meta array, and removing
 * the right entry while leaving every other one alone.
 *
 * simple_sitemap is not installed here. The hook is invoked directly, which is
 * what the module itself does, and it keeps the test independent of a module
 * the library deliberately does not depend on.
 *
 * @group drupal_kit
 */
class FrontPageSitemapLinksAlterKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['drupal_kit', 'system'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    \Drupal::configFactory()
      ->getEditable('system.site')
      ->set('page.front', '/node/9')
      ->save();
  }

  /**
   * Build the link shape simple_sitemap hands to the hook.
   */
  private function link(string $path): array {
    return ['url' => 'https://example.com/' . ltrim($path, '/'), 'meta' => ['path' => $path]];
  }

  /**
   * The front page's node entry is dropped, everything else survives.
   */
  public function testFrontNodeEntryIsRemoved(): void {
    $links = [
      'a' => $this->link(''),
      'b' => $this->link('node/9'),
      'c' => $this->link('node/10'),
      'd' => $this->link('about'),
    ];

    drupal_kit_simple_sitemap_links_alter($links, new \stdClass());

    $this->assertSame(['a', 'c', 'd'], array_keys($links));
  }

  /**
   * A site whose front page is the site root keeps every entry.
   */
  public function testNothingIsRemovedWhenFrontIsSiteRoot(): void {
    \Drupal::configFactory()->getEditable('system.site')->set('page.front', '/')->save();
    $links = ['a' => $this->link('node/9'), 'b' => $this->link('about')];

    drupal_kit_simple_sitemap_links_alter($links, new \stdClass());

    $this->assertSame(['a', 'b'], array_keys($links));
  }

  /**
   * A front page pointing at an aliased path drops that path, not a node.
   *
   * The page.front setting does not have to name a node. When it names an
   * aliased route the same duplicate arises, and node entries have to survive.
   */
  public function testAliasedFrontPathIsRemovedAndNodesSurvive(): void {
    \Drupal::configFactory()->getEditable('system.site')->set('page.front', '/about')->save();
    $links = [
      'a' => $this->link(''),
      'b' => $this->link('about'),
      'c' => $this->link('node/9'),
    ];

    drupal_kit_simple_sitemap_links_alter($links, new \stdClass());

    $this->assertSame(['a', 'c'], array_keys($links));
  }

  /**
   * Links without a meta path are left alone rather than tripping the hook.
   *
   * Simple_sitemap builds meta itself, but the hook runs after every other
   * implementation, so it must tolerate whatever they left behind.
   */
  public function testLinksWithoutMetaPathAreUntouched(): void {
    $links = [
      'a' => ['url' => 'https://example.com/somewhere'],
      'b' => $this->link('node/9'),
    ];

    drupal_kit_simple_sitemap_links_alter($links, new \stdClass());

    $this->assertSame(['a'], array_keys($links));
  }

}
