<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Unit\Component;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ExtensionList;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Theme\ActiveTheme;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\components\Template\ComponentsRegistry;
use Drupal\drupal_kit\Component\ComponentDefinitionRepository;
use Drupal\drupal_kit\Component\InvalidComponentDefinitionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the component definition repository.
 *
 * @coversDefaultClass \Drupal\drupal_kit\Component\ComponentDefinitionRepository
 * @group drupal_kit
 */
class ComponentDefinitionRepositoryTest extends TestCase {

  /**
   * The Components registry stub.
   */
  private ComponentsRegistry&MockObject $registry;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->registry = $this->createMock(ComponentsRegistry::class);
  }

  /**
   * Builds the repository under test.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface|null $cache
   *   The cache bin. A fresh memory bin by default.
   * @param array<string, string> $aliases
   *   Bundle to component name overrides.
   * @param string[] $exclusions
   *   Excluded bundles.
   */
  private function repository(?CacheBackendInterface $cache = NULL, array $aliases = ['html' => 'content'], array $exclusions = ['skipped']): ComponentDefinitionRepository {
    $theme = $this->createMock(ActiveTheme::class);
    $theme->method('getName')->willReturn('fixture_theme');
    $theme_manager = $this->createMock(ThemeManagerInterface::class);
    $theme_manager->method('getActiveTheme')->willReturn($theme);

    return new ComponentDefinitionRepository(
      $this->registry,
      $theme_manager,
      $cache ?? new MemoryBackend($this->createMock(TimeInterface::class)),
      $this->extensionList(ThemeExtensionList::class, [
        'fixture_theme' => 'themes/fixture_theme',
        'fixture_theme_sub' => 'themes/fixture_theme/sub',
      ]),
      $this->extensionList(ModuleExtensionList::class, ['other_module' => 'modules/other_module']),
      dirname(__DIR__, 2) . '/../fixtures/paragraph_components',
      $aliases,
      $exclusions,
    );
  }

  /**
   * Builds an extension list stub.
   *
   * @param class-string $class
   *   The list class.
   * @param array<string, string> $paths
   *   Extension name to directory.
   */
  private function extensionList(string $class, array $paths): ExtensionList {
    /** @var \Drupal\Core\Extension\ExtensionList&\PHPUnit\Framework\MockObject\MockObject $list */
    $list = $this->createMock($class);
    $extensions = [];
    foreach ($paths as $name => $path) {
      $extension = $this->createMock(Extension::class);
      $extension->method('getPath')->willReturn($path);
      $extensions[$name] = $extension;
    }
    $list->method('getList')->willReturn($extensions);
    return $list;
  }

  /**
   * Lets the registry resolve fixture components.
   */
  private function registryResolves(): void {
    $this->registry->method('getTemplate')->willReturnCallback(
      static fn (string $name): ?string => match (TRUE) {
        (bool) preg_match('~^@component/([a-z-]+)/\1\.twig$~', $name, $m) && is_dir(dirname(__DIR__, 2) . '/../fixtures/paragraph_components/component/' . $m[1]) => 'component/' . $m[1] . '/' . $m[1] . '.twig',
        default => NULL,
      }
    );
  }

  /**
   * Bundle names resolve to kebab-case component names.
   *
   * @covers ::resolveComponentName
   */
  #[DataProvider('bundleProvider')]
  public function testResolveComponentName(string $bundle, ?string $expected): void {
    $this->assertSame($expected, $this->repository()->resolveComponentName($bundle));
  }

  /**
   * Data provider for bundle resolution.
   *
   * @return array<int|string, array<int, mixed>>
   *   The cases.
   */
  public static function bundleProvider(): array {
    return [
      'snake case becomes kebab case' => ['article_video', 'article-video'],
      'single word stays' => ['contact', 'contact'],
      'alias wins' => ['html', 'content'],
      'excluded bundle has no component' => ['skipped', NULL],
      'digits are allowed after the first letter' => ['signpost_2', 'signpost-2'],
      'leading digit is not a machine name' => ['2col', NULL],
      'upper case is not a machine name' => ['Contact', NULL],
      'dots are rejected' => ['a.b', NULL],
      'empty string is rejected' => ['', NULL],
      'double underscore leaves a double dash' => ['a__b', NULL],
      'trailing underscore leaves a dash' => ['a_', NULL],
    ];
  }

  /**
   * Component names are validated.
   *
   * @covers ::isValidComponentName
   */
  #[DataProvider('nameProvider')]
  public function testIsValidComponentName(string $name, bool $valid): void {
    $this->assertSame($valid, $this->repository()->isValidComponentName($name));
  }

  /**
   * Data provider for name validation.
   *
   * @return array<int|string, array<int, mixed>>
   *   The cases.
   */
  public static function nameProvider(): array {
    return [
      ['contact', TRUE],
      ['article-video', TRUE],
      ['../etc/passwd', FALSE],
      ['a/b', FALSE],
      ['a b', FALSE],
      ["a\0b", FALSE],
      ['-a', FALSE],
      ['a-', FALSE],
      ['a--b', FALSE],
      ["ok\n", FALSE],
    ];
  }

  /**
   * An invalid name never reaches the registry or the file system.
   *
   * @covers ::load
   */
  public function testInvalidNameTouchesNoPath(): void {
    $this->registry->expects($this->never())->method('getTemplate');
    $this->assertNull($this->repository()->load('../ok'));
  }

  /**
   * A valid definition loads from the YAML next to the template.
   *
   * @covers ::load
   */
  public function testLoadsDefinition(): void {
    $this->registryResolves();
    $definition = $this->repository()->load('ok');
    $this->assertNotNull($definition);
    $this->assertSame('ok', $definition->getName());
    $this->assertSame('component/ok/ok.yaml', $definition->getPath());
    $this->assertNull($definition->getProvider());
    $this->assertSame(['title' => ['type' => 'text', 'role' => 'field']], $definition->getFields());
    $this->assertSame('Ok', $definition->get('name'));
  }

  /**
   * A bundle loads through its resolved name.
   *
   * @covers ::loadForBundle
   */
  public function testLoadForBundle(): void {
    $this->registryResolves();
    $this->assertSame('ok', $this->repository()->loadForBundle('ok')?->getName());
    $this->assertNull($this->repository()->loadForBundle('skipped'));
  }

  /**
   * A component without a template has no definition.
   *
   * @covers ::load
   */
  public function testMissingTemplate(): void {
    $this->registryResolves();
    $this->assertNull($this->repository()->load('nothing-here'));
  }

  /**
   * Without the Components module nothing has a definition.
   *
   * @covers ::load
   */
  public function testNoRegistry(): void {
    $theme = $this->createMock(ActiveTheme::class);
    $theme->method('getName')->willReturn('fixture_theme');
    $theme_manager = $this->createMock(ThemeManagerInterface::class);
    $theme_manager->method('getActiveTheme')->willReturn($theme);
    $repository = new ComponentDefinitionRepository(
      NULL,
      $theme_manager,
      new MemoryBackend($this->createMock(TimeInterface::class)),
      $this->createMock(ThemeExtensionList::class),
      $this->createMock(ModuleExtensionList::class),
      '/nonexistent',
    );

    $this->assertNull($repository->load('ok'));
  }

  /**
   * A malformed file throws the recoverable exception.
   *
   * @covers ::load
   */
  #[DataProvider('malformedProvider')]
  public function testMalformedDefinition(string $component): void {
    $this->registryResolves();
    $this->expectException(InvalidComponentDefinitionException::class);
    $this->expectExceptionMessage("component/$component/$component.yaml");
    $this->repository()->load($component);
  }

  /**
   * A malformed file is read once. The cached verdict throws again.
   *
   * @covers ::load
   */
  public function testMalformedDefinitionIsCached(): void {
    $this->registry->expects($this->once())->method('getTemplate')->willReturn('component/bad-fields/bad-fields.twig');
    $bin = new MemoryBackend($this->createMock(TimeInterface::class));
    for ($i = 0; $i < 2; $i++) {
      try {
        // A fresh repository each time, so only the bin can answer.
        $this->repository($bin)->load('bad-fields');
        $this->fail('Expected an exception.');
      }
      catch (InvalidComponentDefinitionException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  /**
   * The provider is the deepest extension directory that holds the template.
   *
   * @covers ::load
   */
  #[DataProvider('ownerProvider')]
  public function testProvider(string $template, ?string $expected): void {
    $this->registry->method('getTemplate')->willReturn($template);
    // The YAML lives in the fixtures; only the owner lookup differs.
    $repository = $this->repository();
    $reflection = new \ReflectionMethod($repository, 'findOwner');
    $this->assertSame($expected, $reflection->invoke($repository, $template));
  }

  /**
   * Data provider for owners.
   *
   * @return array<string, array<int, mixed>>
   *   The cases.
   */
  public static function ownerProvider(): array {
    return [
      'theme' => ['themes/fixture_theme/templates/component/ok/ok.twig', 'fixture_theme'],
      'sub theme below its base wins' => ['themes/fixture_theme/sub/templates/ok/ok.twig', 'fixture_theme_sub'],
      'module' => ['modules/other_module/components/ok/ok.twig', 'other_module'],
      'prefix of another name is no match' => ['themes/fixture_theme_x/ok.twig', NULL],
      'unknown' => ['somewhere/ok.twig', NULL],
    ];
  }

  /**
   * Data provider for malformed files.
   *
   * @return array<int|string, array<int, mixed>>
   *   The cases.
   */
  public static function malformedProvider(): array {
    return [
      'invalid yaml' => ['bad-yaml'],
      'fields is not a map' => ['bad-fields'],
      'document is a scalar' => ['scalar'],
    ];
  }

  /**
   * A second load in the same request reads neither registry nor cache again.
   *
   * @covers ::load
   */
  public function testStaticCache(): void {
    $this->registry->expects($this->once())->method('getTemplate')->willReturn('component/ok/ok.twig');
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->expects($this->once())->method('get')->willReturn(FALSE);
    $cache->expects($this->once())->method('set');
    $repository = $this->repository($cache);
    $first = $repository->load('ok');
    $this->assertEquals($first, $repository->load('ok'));
  }

  /**
   * The cache stores the definition with the registry tag.
   *
   * @covers ::load
   */
  public function testCacheEntryIsTagged(): void {
    $this->registry->method('getTemplate')->willReturn('component/ok/ok.twig');
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);
    $cache->expects($this->once())->method('set')->with(
      'drupal_kit.component_definition:fixture_theme:ok',
      $this->callback(static fn ($data) => $data['name'] === 'ok' && $data['path'] === 'component/ok/ok.yaml'),
      CacheBackendInterface::CACHE_PERMANENT,
      ['components_registry', 'drupal_kit_component_definitions'],
    );
    $this->repository($cache)->load('ok');
  }

  /**
   * A fresh repository takes the definition from the cache bin.
   *
   * @covers ::load
   */
  public function testCacheHitAvoidsRegistryAndDisk(): void {
    $this->registry->expects($this->once())->method('getTemplate')->willReturn('component/ok/ok.twig');
    $bin = new MemoryBackend($this->createMock(TimeInterface::class));
    $this->repository($bin)->load('ok');
    // The second repository has the same bin and a registry that must stay
    // silent.
    $this->registry = $this->createMock(ComponentsRegistry::class);
    $this->registry->expects($this->never())->method('getTemplate');
    $definition = $this->repository($bin)->load('ok');
    $this->assertSame('ok', $definition?->getName());
  }

  /**
   * A missing definition is cached, so the disk is not hit again.
   *
   * @covers ::load
   */
  public function testMissingDefinitionIsCached(): void {
    $this->registry->expects($this->once())->method('getTemplate')->willReturn(NULL);
    $bin = new MemoryBackend($this->createMock(TimeInterface::class));
    $this->assertNull($this->repository($bin)->load('ok'));
    $this->assertNull($this->repository($bin)->load('ok'));
  }

}
