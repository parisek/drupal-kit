<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\drupal_kit\Component\ComponentDefinitionRepositoryInterface;
use Drupal\drupal_kit\Component\InvalidComponentDefinitionException;

/**
 * Tests the component definition repository against the real registry.
 *
 * The unit tests stub the registry. This test installs two real themes, so
 * "Twig precedence decides the definition" is checked end to end.
 *
 * @coversDefaultClass \Drupal\drupal_kit\Component\ComponentDefinitionRepository
 * @group drupal_kit
 */
class ComponentDefinitionRepositoryKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'drupal_kit',
    'components',
    'system',
    'user',
    'twig_tweak',
    'extra_field',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->container->get('theme_installer')->install(['dk_base', 'dk_active']);
    $this->config('system.theme')->set('default', 'dk_active')->save();
    $this->container->get('theme.manager')->setActiveTheme(
      $this->container->get('theme.initialization')->initTheme('dk_active')
    );
  }

  /**
   * Gets the repository.
   */
  private function repository(): ComponentDefinitionRepositoryInterface {
    return $this->container->get('drupal_kit.component_definition_repository');
  }

  /**
   * The active theme wins over its base theme.
   *
   * @covers ::load
   */
  public function testActiveThemeWinsOverBaseTheme(): void {
    $definition = $this->repository()->load('precedence');

    $this->assertNotNull($definition);
    $this->assertSame('active', $definition->getData()['fields']['title']['from']);
    $this->assertStringContainsString('dk_active', $definition->getDirectory());
  }

  /**
   * A component only the base theme has is found there.
   *
   * @covers ::load
   */
  public function testBaseThemeIsTheFallback(): void {
    $definition = $this->repository()->load('base-only');

    $this->assertNotNull($definition);
    $this->assertStringContainsString('dk_base', $definition->getDirectory());
  }

  /**
   * A template with no YAML beside it, and an unknown name, have no definition.
   *
   * @covers ::load
   */
  public function testMissingDefinitionIsNull(): void {
    $this->assertNull($this->repository()->load('no-yaml'));
    $this->assertNull($this->repository()->load('does-not-exist'));
  }

  /**
   * A malformed definition throws, and the failure is cached.
   *
   * @covers ::load
   */
  public function testMalformedDefinitionThrows(): void {
    foreach ([1, 2] as $attempt) {
      try {
        $this->repository()->load('broken');
        $this->fail("Expected an exception on attempt $attempt.");
      }
      catch (InvalidComponentDefinitionException $e) {
        $this->assertStringContainsString('must be a map', $e->getMessage());
      }
    }
  }

  /**
   * A cache rebuild drops the cached definitions.
   *
   * @covers ::load
   */
  public function testRegistryTagInvalidatesCachedDefinitions(): void {
    $this->assertNotNull($this->repository()->load('precedence'));
    $cid = 'drupal_kit.component_definition:dk_active:precedence';
    $this->assertNotFalse($this->container->get('cache.discovery')->get($cid));

    $this->container->get('cache_tags.invalidator')->invalidateTags(['components_registry']);

    $this->assertFalse($this->container->get('cache.discovery')->get($cid));
  }

}
