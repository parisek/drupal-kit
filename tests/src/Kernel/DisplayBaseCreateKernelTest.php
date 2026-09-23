<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * DisplayBase::create() wires the container services it claims to.
 *
 * This is the half of DisplayBase nothing tested. `__call` has fourteen
 * cases between the unit and kernel suites; `create()` had none, and the
 * class sat at 13% line coverage because of it.
 *
 * The gap was self-concealing. DisplayBaseKernelTest builds its subject by
 * hand, listing the same thirteen constructor arguments in the same order
 * as create() does — so the test carries its own copy of the ordering it is
 * supposed to protect. Reorder create() and that test still passes.
 *
 * Why the ordering is worth a test at all: a swap between two services PHP
 * can tell apart is a TypeError and obvious. A swap between two it cannot
 * is silent, and the class then asks the wrong service for the rest of its
 * life. DisplayBase is a base class consumers extend, so the failure would
 * surface in their project and not in this repository.
 *
 * @coversDefaultClass \Drupal\drupal_kit\DisplayBase
 * @group drupal_kit
 */
class DisplayBaseCreateKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'drupal_kit',
    'system',
    'user',
    'block',
    'extra_field',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
  }

  /**
   * Every property, and the container service it must hold.
   *
   * @return array<string, array{string, string}>
   *   Property name and service id, keyed by property name.
   */
  public static function dependencyProvider(): array {
    $map = [
      'entityTypeManager' => 'entity_type.manager',
      'routeMatch' => 'current_route_match',
      'languageManager' => 'language_manager',
      'entityRepository' => 'entity.repository',
      'configFactory' => 'config.factory',
      'connection' => 'database',
      'entityHelper' => 'drupal_kit.entity_helper',
      'pathMatcher' => 'path.matcher',
      'requestStack' => 'request_stack',
      'transliteration' => 'transliteration',
    ];

    return array_combine(
      array_keys($map),
      array_map(static fn(string $p, string $s): array => [$p, $s], array_keys($map), $map),
    );
  }

  /**
   * Build the plugin the way the plugin manager does.
   */
  private function create(): DisplayBaseStub {
    return DisplayBaseStub::create(
      $this->container,
      [],
      'display_base_stub',
      ['provider' => 'drupal_kit'],
    );
  }

  /**
   * The property holds the service, not merely something of its type.
   *
   * Identity, not type. Container services are shared, so assertSame is
   * available and is the stronger claim: two services sharing an interface
   * would both satisfy assertInstanceOf and only one is correct.
   *
   * @covers ::create
   * @covers ::__construct
   * @dataProvider dependencyProvider
   */
  public function testCreateInjectsTheService(string $property, string $service): void {
    $display = $this->create();

    $reflection = new \ReflectionProperty($display, $property);
    $this->assertSame(
      $this->container->get($service),
      $reflection->getValue($display),
      "$property must hold the $service service.",
    );
  }

  /**
   * The plugin's own three arguments reach the parent.
   *
   * @covers ::create
   */
  public function testCreatePassesThePluginDefinitionThrough(): void {
    $display = $this->create();

    $this->assertSame('display_base_stub', $display->getPluginId());
    $this->assertSame(['provider' => 'drupal_kit'], $display->getPluginDefinition());
  }

}
