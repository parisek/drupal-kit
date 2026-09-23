<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Kernel\Plugin\Filter;

use Drupal\KernelTests\KernelTestBase;

/**
 * Every filter this module ships is still discoverable by its ID.
 *
 * The five existing filter tests are unit tests: they construct the class
 * and call process(). That proves the transformation and says nothing about
 * whether Drupal can find the plugin — so the move from `@Filter`
 * annotations to `#[Filter]` attributes could have broken discovery with
 * all five staying green.
 *
 * The IDs are the contract. A text format stores them in config, so an ID
 * that stops resolving does not fail loudly: the filter silently stops
 * running and body text renders unprocessed.
 *
 * @group drupal_kit
 */
class FilterDiscoveryKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['drupal_kit', 'system', 'filter'];

  /**
   * The plugin IDs this module has always published.
   *
   * @return array<string, array{string, string}>
   *   Plugin ID and expected title, keyed by ID.
   */
  public static function filterProvider(): array {
    return [
      'filter_image' => ['filter_image', 'Image Filter'],
      'filter_links' => ['filter_links', 'Links Filter'],
      'filter_table' => ['filter_table', 'Table Filter'],
      'filter_typography' => ['filter_typography', 'Typography Filter'],
      'filter_youtube' => ['filter_youtube', 'Youtube Filter'],
    ];
  }

  /**
   * The plugin manager resolves the ID, with its metadata intact.
   *
   * @dataProvider filterProvider
   */
  public function testTheFilterIsDiscoverable(string $id, string $title): void {
    $definitions = $this->container->get('plugin.manager.filter')->getDefinitions();

    $this->assertArrayHasKey($id, $definitions, "$id is no longer discoverable.");
    $this->assertSame($title, (string) $definitions[$id]['title']);
    $this->assertSame('drupal_kit', $definitions[$id]['provider']);
  }

  /**
   * The module publishes these five and nothing else.
   *
   * A plugin gained by accident is as much a surprise to a site owner as
   * one lost: it appears in the text-format UI for every editor to enable.
   */
  public function testTheModulePublishesExactlyTheseFilters(): void {
    $ours = array_keys(array_filter(
      $this->container->get('plugin.manager.filter')->getDefinitions(),
      static fn(array $definition): bool => $definition['provider'] === 'drupal_kit',
    ));
    sort($ours);

    $this->assertSame(array_keys(self::filterProvider()), $ours);
  }

}
