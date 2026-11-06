<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Unit\Paragraphs;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Config\Entity\ThirdPartySettingsInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\drupal_kit\Component\ComponentDefinition;
use Drupal\drupal_kit\Component\ComponentDefinitionRepositoryInterface;
use Drupal\drupal_kit\Component\InvalidComponentDefinitionException;
use Drupal\drupal_kit\Services\CacheScope;
use Drupal\drupal_kit\Services\EntityHelper;
use Drupal\drupal_kit_paragraphs\Mapper\ParagraphComponentMapper;
use Drupal\drupal_kit_paragraphs\Mapper\ParagraphMapperInterface;
use Drupal\drupal_kit_paragraphs\Mapper\ParagraphMapperManagerInterface;
use Drupal\drupal_kit_paragraphs\Mapping\MappingContext;
use Drupal\drupal_kit_paragraphs\Mapping\ParagraphMapping;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\drupal_kit\Services\MediaArrayBuilder;
use Drupal\drupal_kit\Services\MenuActiveTrailResolver;
use Drupal\drupal_kit\Services\MenuTreeBuilder;
use Drupal\drupal_kit\Services\TaxonomyTreeBuilder;
use Symfony\Component\HttpFoundation\RequestStack;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/fixtures/stubs/ParagraphInterface.php';

/**
 * Tests for the generic paragraph mapper, with a stubbed EntityHelper.
 *
 * @coversDefaultClass \Drupal\drupal_kit_paragraphs\Mapper\ParagraphComponentMapper
 * @group drupal_kit
 */
class ParagraphComponentMapperTest extends TestCase {

  /**
   * The component definition repository stub.
   */
  private ComponentDefinitionRepositoryInterface&MockObject $repository;

  /**
   * The entity helper stub.
   */
  private EntityHelper&MockObject $helper;

  /**
   * The mapper plugin manager stub.
   */
  private ParagraphMapperManagerInterface&MockObject $manager;

  /**
   * The module handler stub.
   */
  private ModuleHandlerInterface&MockObject $moduleHandler;

  /**
   * The theme manager stub.
   */
  private ThemeManagerInterface&MockObject $themeManager;

  /**
   * The logger channel stub.
   */
  private LoggerChannelInterface&MockObject $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Cache::mergeContexts() asserts valid context tokens through this service.
    $contexts_manager = $this->createMock(CacheContextsManager::class);
    $contexts_manager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $contexts_manager);
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translateString')->willReturnCallback(
      static fn ($markup) => strtr($markup->getUntranslatedString(), $markup->getArguments())
    );
    $container->set('string_translation', $translation);
    \Drupal::setContainer($container);

    $this->repository = $this->createMock(ComponentDefinitionRepositoryInterface::class);
    $this->helper = $this->createMock(EntityHelper::class);
    $this->helper->method('beginCacheScope')->willReturn(new CacheScope(1, NULL));
    $this->helper->method('beginCacheScope')->willReturn(new CacheScope(1, NULL));
    $this->helper->method('endCacheScope')->willReturn(new CacheableMetadata());
    $this->manager = $this->createMock(ParagraphMapperManagerInterface::class);
    $this->manager->method('getMappersForComponent')->willReturn([]);
    $this->moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $this->themeManager = $this->createMock(ThemeManagerInterface::class);
    $this->logger = $this->createMock(LoggerChannelInterface::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * Builds the mapper under test.
   */
  private function mapper(?EntityHelper $helper = NULL): ParagraphComponentMapper {
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($this->logger);
    return new ParagraphComponentMapper(
      $this->repository,
      $helper ?? $this->helper,
      $this->manager,
      $this->moduleHandler,
      $this->themeManager,
      $factory,
      ['page'],
    );
  }

  /**
   * Builds a paragraph mock with a field map.
   *
   * @param array<string, array{0: string, 1?: int, 2?: string, 3?: int}> $fields
   *   Field name to [type, cardinality, target type, instance cardinality
   *   override].
   * @param string $bundle
   *   The paragraph bundle.
   * @param mixed $parent
   *   The parent entity.
   * @param string[] $tags
   *   The cache tags of the paragraph.
   */
  private function paragraph(array $fields, string $bundle = 'contact', mixed $parent = NULL, array $tags = ['paragraph:1']): ParagraphInterface&MockObject {
    $paragraph = $this->createMock(ParagraphInterface::class);
    $paragraph->method('bundle')->willReturn($bundle);
    $paragraph->method('id')->willReturn('1');
    $paragraph->method('getParentEntity')->willReturn($parent);
    $paragraph->method('getCacheTags')->willReturn($tags);
    $paragraph->method('getCacheContexts')->willReturn([]);
    $paragraph->method('getCacheMaxAge')->willReturn(-1);
    $paragraph->method('hasField')->willReturnCallback(static fn ($name) => isset($fields[$name]));
    $paragraph->method('getFieldDefinition')->willReturnCallback(function ($name) use ($fields) {
      if (!isset($fields[$name])) {
        return NULL;
      }
      $storage = $this->createMock(FieldStorageDefinitionInterface::class);
      $storage->method('getCardinality')->willReturn($fields[$name][1] ?? 1);
      $storage->method('getSetting')->willReturn($fields[$name][2] ?? NULL);
      $definition = $this->createMockForIntersectionOfInterfaces([
        FieldDefinitionInterface::class,
        ThirdPartySettingsInterface::class,
      ]);
      $definition->method('getType')->willReturn($fields[$name][0]);
      $definition->method('getFieldStorageDefinition')->willReturn($storage);
      $definition->method('getThirdPartySetting')->willReturn($fields[$name][3] ?? NULL);
      return $definition;
    });
    return $paragraph;
  }

  /**
   * Lets the repository return a definition for the bundle.
   *
   * @param array<string, mixed> $fields
   *   The `fields` tree.
   * @param string $name
   *   The component name.
   */
  private function definition(array $fields, string $name = 'contact'): void {
    $this->repository->method('loadForBundle')->willReturn(new ComponentDefinition($name, "component/$name/$name.yaml", ['fields' => $fields], 'fixture_theme'));
  }

  /**
   * Makes mapFields echo "<field>" so the test sees which field was read.
   */
  private function mapFieldsEchoesFieldName(): void {
    $this->helper->method('mapFields')->willReturnCallback(
      static fn ($entity, array $map) => array_map(
        static fn (array $config) => '<' . $config['field'] . '>',
        $map,
      )
    );
  }

  /**
   * Fields map by default name, by drupal.field and through groups.
   *
   * @covers ::map
   * @covers ::mapNodes
   * @covers ::resolveFieldName
   */
  public function testMapsDefinitionFields(): void {
    $this->mapFieldsEchoesFieldName();
    $this->definition([
      'heading' => [
        'type' => 'object',
        'role' => 'field',
        'fields' => ['title' => ['type' => 'text', 'role' => 'field']],
      ],
      'phone' => ['type' => 'text', 'role' => 'field', 'drupal' => ['storage' => 'telephone']],
      'address' => ['type' => 'richtext', 'role' => 'field', 'drupal' => ['field' => 'field_text']],
    ]);
    $paragraph = $this->paragraph([
      'field_title' => ['string'],
      'field_phone' => ['telephone'],
      'field_text' => ['text_long'],
    ]);

    $mapping = $this->mapper()->map($paragraph, 'default');

    $this->assertFalse($mapping->isEmpty());
    $this->assertSame('contact', $mapping->getComponent());
    $this->assertSame('contact', $mapping->getTemplate());
    $this->assertSame('<field_title>', $mapping->get('heading')['title']);
    $this->assertSame('<field_phone>', $mapping->get('phone'));
    $this->assertSame('<field_text>', $mapping->get('address'));
  }

  /**
   * Only role field maps. A child inherits the role of its parent.
   *
   * @covers ::mapNodes
   */
  public function testRolesAreFilteredAndInherited(): void {
    $this->mapFieldsEchoesFieldName();
    $this->definition([
      'q' => ['type' => 'text', 'role' => 'query'],
      'p' => ['type' => 'text', 'role' => 'parent'],
      'g' => ['type' => 'text', 'role' => 'global'],
      'norole' => ['type' => 'text'],
      'group' => [
        'type' => 'object',
        'role' => 'field',
        'fields' => ['inner' => ['type' => 'text']],
      ],
      'blocked' => [
        'type' => 'object',
        'role' => 'query',
        'fields' => ['deep' => ['type' => 'text', 'role' => 'field']],
      ],
    ]);
    $paragraph = $this->paragraph([
      'field_q' => ['string'],
      'field_p' => ['string'],
      'field_g' => ['string'],
      'field_norole' => ['string'],
      'field_inner' => ['string'],
      'field_deep' => ['string'],
    ]);

    $data = $this->mapper()->map($paragraph, 'default')->getData();

    $this->assertArrayNotHasKey('q', $data);
    $this->assertArrayNotHasKey('p', $data);
    $this->assertArrayNotHasKey('g', $data);
    $this->assertArrayNotHasKey('norole', $data);
    $this->assertArrayNotHasKey('blocked', $data);
    $this->assertSame(['inner' => '<field_inner>'], $data['group']);
  }

  /**
   * An unknown field is skipped with a diagnostic, never mapped to nothing.
   *
   * @covers ::mapNodes
   */
  public function testMissingFieldIsSkippedWithDiagnostic(): void {
    $this->helper->expects($this->never())->method('mapFields');
    $this->definition([
      'ghost' => ['type' => 'text', 'role' => 'field'],
      'named' => ['type' => 'text', 'role' => 'field', 'drupal' => ['field' => 'field_elsewhere']],
    ]);

    $mapping = $this->mapper()->map($this->paragraph([]), 'default');

    $this->assertArrayNotHasKey('ghost', $mapping->getData());
    $this->assertArrayNotHasKey('named', $mapping->getData());
    $this->assertSame([
      'ghost: entity has no field field_ghost.',
      'named: entity has no field field_elsewhere.',
    ], $mapping->getDiagnostics());
  }

  /**
   * Shape mismatches become diagnostics and change nothing in the output.
   *
   * @covers ::checkShape
   */
  public function testShapeDiagnostics(): void {
    $this->mapFieldsEchoesFieldName();
    $this->definition([
      'video' => ['type' => 'media', 'role' => 'field', 'multiple' => TRUE],
      'link' => ['type' => 'link', 'role' => 'field'],
      'tags' => ['type' => 'text', 'role' => 'field'],
    ]);
    $paragraph = $this->paragraph([
      'field_video' => ['entity_reference', 1, 'media'],
      'field_link' => ['string'],
      'field_tags' => ['string', -1],
    ]);

    $mapping = $this->mapper()->map($paragraph, 'default');

    $this->assertSame('<field_video>', $mapping->get('video'));
    $this->assertEqualsCanonicalizing([
      "video: YAML says multiple, the field holds one value.",
      "link: YAML type 'link' does not fit the string field.",
      'tags: YAML says single, the field allows -1 values.',
    ], $mapping->getDiagnostics());
  }

  /**
   * The per-instance cardinality override decides the shape diagnostic.
   *
   * @covers ::checkShape
   */
  public function testInstanceCardinalityOverrideWinsInShapeCheck(): void {
    $this->mapFieldsEchoesFieldName();
    $this->definition(['image' => ['type' => 'media', 'role' => 'field']]);
    // Storage allows many, the instance allows one: YAML single is right.
    $paragraph = $this->paragraph(['field_image' => ['entity_reference', -1, 'media', 1]]);

    $this->assertSame([], $this->mapper()->map($paragraph, 'default')->getDiagnostics());
  }

  /**
   * A list with child fields maps every referenced child.
   *
   * @covers ::mapChildren
   */
  public function testNestedList(): void {
    $child_a = $this->paragraph(['field_label' => ['string']], 'numbers_item');
    $child_b = $this->paragraph(['field_label' => ['string']], 'numbers_item');
    $this->helper->expects($this->once())->method('getEntityReferenceField')
      ->with($this->anything(), 'field_paragraphs', ['return_format' => 'array'])
      ->willReturn([$child_a, $child_b]);
    $this->helper->method('mapFields')->willReturnCallback(
      static fn ($entity, array $map) => array_map(
        static fn (array $c) => $entity === $child_a ? 'A' : 'B',
        $map,
      )
    );
    $this->definition([
      'items' => [
        'type' => 'list',
        'role' => 'field',
        'drupal' => ['field' => 'field_paragraphs'],
        'fields' => ['label' => ['type' => 'text', 'role' => 'field']],
      ],
    ], 'numbers');
    $parent = $this->paragraph(['field_paragraphs' => ['entity_reference_revisions', -1]], 'numbers');

    $mapping = $this->mapper()->map($parent, 'default');

    $this->assertSame([['label' => 'A'], ['label' => 'B']], $mapping->get('items'));
  }

  /**
   * A list that references nothing leaves its key out.
   *
   * @covers ::mapChildren
   */
  public function testEmptyListLeavesKeyOut(): void {
    $this->helper->method('getEntityReferenceField')->willReturn([]);
    $this->definition([
      'items' => [
        'type' => 'list',
        'role' => 'field',
        'drupal' => ['field' => 'field_paragraphs'],
        'fields' => ['label' => ['type' => 'text']],
      ],
    ], 'numbers');
    $parent = $this->paragraph(['field_paragraphs' => ['entity_reference_revisions', -1]], 'numbers');

    $this->assertArrayNotHasKey('items', $this->mapper()->map($parent, 'default')->getData());
  }

  /**
   * The framework fields keep the old behavior.
   *
   * @covers ::mapFrameworkFields
   */
  public function testFrameworkFields(): void {
    $this->helper->method('getTextField')->willReturnCallback(
      static fn ($entity, $name) => ['title' => 'Title', 'wrapper_id' => 'anchor', 'wrapper_classes' => ''][$name] ?? FALSE
    );
    $this->helper->method('getTextareaField')->willReturn('Perex');
    $this->helper->method('getSelectField')->willReturnCallback(
      static fn ($entity, $name) => ['spacing' => 'lg', 'border' => FALSE][$name] ?? FALSE
    );
    $this->definition([]);
    $parent = $this->createMock(NodeInterface::class);
    $parent->method('bundle')->willReturn('page');

    $data = $this->mapper()->map($this->paragraph([], 'contact', $parent), 'default')->getData();

    $this->assertSame('container', $data['container']);
    $this->assertSame(['title' => 'Title', 'perex' => 'Perex'], $data['heading']);
    $this->assertSame('anchor', $data['wrapper_id']);
    $this->assertSame('lg', $data['spacing']);
    $this->assertArrayNotHasKey('wrapper_classes', $data);
    $this->assertArrayNotHasKey('border', $data);
  }

  /**
   * A paragraph under another parent gets no container.
   *
   * @covers ::mapFrameworkFields
   */
  public function testNoContainerForNestedParagraph(): void {
    $this->definition([]);
    $data = $this->mapper()->map($this->paragraph([], 'contact', $this->createMock(ParagraphInterface::class)), 'default')->getData();
    $this->assertSame('', $data['container']);
  }

  /**
   * Definition values override framework values key by key.
   *
   * @covers ::merge
   */
  public function testDefinitionOverridesFrameworkHeading(): void {
    $this->helper->method('getTextField')->willReturn('Framework');
    $this->helper->method('getTextareaField')->willReturn('Perex');
    $this->helper->method('mapFields')->willReturn(['title' => 'Definition']);
    $this->definition([
      'heading' => ['type' => 'object', 'role' => 'field', 'fields' => ['title' => ['type' => 'text']]],
    ]);

    $heading = $this->mapper()->map($this->paragraph(['field_title' => ['string']]), 'default')->get('heading');

    $this->assertEquals(['title' => 'Definition', 'perex' => 'Perex'], $heading);
  }

  /**
   * A missing definition renders the old "implementation is missing" alert.
   *
   * @covers ::map
   * @covers ::mapAlert
   */
  public function testMissingDefinitionRendersAlert(): void {
    $this->repository->method('loadForBundle')->willReturn(NULL);
    $this->logger->expects($this->once())->method('warning');
    $this->helper->expects($this->never())->method('mapFields');
    $this->moduleHandler->expects($this->never())->method('alter');
    $this->manager = $this->createMock(ParagraphMapperManagerInterface::class);
    $this->manager->expects($this->never())->method('getMappersForComponent');
    $this->helper->method('getTextField')->willReturn('Title');
    $this->helper->method('getTextareaField')->willReturn('');

    $mapping = $this->mapper()->map($this->paragraph([], 'article_video'), 'default');

    $this->assertAlert($mapping, 'article-video');
    // The framework fields stay, like in the old display.
    $this->assertSame(['title' => 'Title'], $mapping->get('heading'));
  }

  /**
   * A malformed definition logs and renders the same alert.
   *
   * @covers ::map
   * @covers ::mapAlert
   */
  public function testMalformedDefinitionRendersAlert(): void {
    $this->repository->method('loadForBundle')->willThrowException(new InvalidComponentDefinitionException('bad yaml'));
    $this->logger->expects($this->once())->method('error')->with($this->stringContains('malformed'), $this->arrayHasKey('@message'));
    $this->moduleHandler->expects($this->never())->method('alter');

    $mapping = $this->mapper()->map($this->paragraph([], 'contact'), 'default');

    $this->assertAlert($mapping, 'contact');
  }

  /**
   * A malformed node in the fields tree renders the alert too.
   *
   * @covers ::mapNodes
   */
  public function testMalformedNodeRendersAlert(): void {
    $this->definition(['broken' => 'not a map']);
    $this->logger->expects($this->once())->method('error');

    $mapping = $this->mapper()->map($this->paragraph([]), 'default');

    $this->assertAlert($mapping, 'contact');
  }

  /**
   * Asserts the alert shape and the theme context.
   */
  private function assertAlert(ParagraphMapping $mapping, string $template_name): void {
    $this->assertFalse($mapping->isEmpty());
    $this->assertSame('alert', $mapping->getTemplate());
    $this->assertSame('error', $mapping->get('type'));
    $this->assertSame('container', $mapping->get('container'));
    $this->assertStringContainsString($template_name, (string) $mapping->get('message'));
    $this->assertStringContainsString('<strong>', (string) $mapping->get('message'));
    $build = $mapping->toRenderArray();
    $this->assertSame('custom_component', $build['#theme']);
    $this->assertSame('alert', $build['#template']);
    $this->assertContains('theme', $build['#cache']['contexts']);
    $this->assertSame(['paragraph:1'], $build['#cache']['tags']);
  }

  /**
   * The normal path carries the theme context too.
   *
   * @covers ::map
   */
  public function testNormalPathHasThemeContext(): void {
    $this->definition([]);
    $build = $this->mapper()->map($this->paragraph([]), 'default')->toRenderArray();
    $this->assertContains('theme', $build['#cache']['contexts']);
  }

  /**
   * The cacheability of the paragraph and of the helper both end up on the map.
   *
   * @covers ::map
   */
  public function testCacheabilityMerges(): void {
    $this->helper = $this->createMock(EntityHelper::class);
    $this->helper->method('beginCacheScope')->willReturn(new CacheScope(1, NULL));
    $this->helper->expects($this->once())->method('endCacheScope')->with($this->isInstanceOf(CacheScope::class))
      ->willReturn((new CacheableMetadata())->setCacheTags(['media:7'])->setCacheContexts(['languages:language_content'])->setCacheMaxAge(120));
    $this->definition([]);

    $metadata = $this->mapper()->map($this->paragraph([]), 'default')->getCacheableMetadata();

    $this->assertEqualsCanonicalizing(['paragraph:1', 'media:7'], $metadata->getCacheTags());
    $this->assertEqualsCanonicalizing(['languages:language_content', 'theme'], $metadata->getCacheContexts());
    $this->assertSame(120, $metadata->getCacheMaxAge());
  }

  /**
   * An unexpected exception propagates, and the accumulator is still drained.
   *
   * @covers ::map
   */
  public function testUnexpectedExceptionPropagatesAndDrains(): void {
    $this->helper = $this->createMock(EntityHelper::class);
    $this->helper->method('beginCacheScope')->willReturn(new CacheScope(1, NULL));
    $this->helper->expects($this->once())->method('endCacheScope')->with($this->isInstanceOf(CacheScope::class))
      ->willReturn(new CacheableMetadata());
    $this->helper->method('getTextField')->willThrowException(new \RuntimeException('boom'));
    $this->definition([]);
    $this->logger->expects($this->never())->method('error');

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('boom');
    $this->mapper()->map($this->paragraph([]), 'default');
  }

  /**
   * Plugins run in the order the manager gives, then module, then theme hooks.
   *
   * @covers ::build
   */
  public function testPluginsAndHooksRunInOrder(): void {
    $this->helper->method('mapFields')->willReturn([]);
    $this->definition([], 'article-video');

    $calls = new \ArrayObject();
    $plugin = new class($calls) implements ParagraphMapperInterface {

      /**
       * Constructs the stub.
       *
       * @param \ArrayObject<int, string> $calls
       *   The shared call log.
       */
      public function __construct(private \ArrayObject $calls) {}

      /**
       * {@inheritdoc}
       */
      public function alter(ParagraphMapping $mapping, MappingContext $context): void {
        $this->calls->append('plugin:' . $context->getComponent() . ':' . $context->getViewMode());
        $mapping->set('from_plugin', TRUE)->setTemplate('custom');
      }

    };
    $manager = $this->createMock(ParagraphMapperManagerInterface::class);
    $manager->expects($this->once())->method('getMappersForComponent')->with('article-video', 'fixture_theme')->willReturn(['p' => $plugin]);
    $this->manager = $manager;

    $types = ['drupal_kit_paragraph_mapping', 'drupal_kit_paragraph_mapping_article_video'];
    $this->moduleHandler->expects($this->once())->method('alter')
      ->with($types, $this->isInstanceOf(ParagraphMapping::class), $this->isInstanceOf(MappingContext::class))
      ->willReturnCallback(function () use ($calls) {
        $calls->append('module');
      });
    $this->themeManager->expects($this->once())->method('alter')
      ->with($types, $this->isInstanceOf(ParagraphMapping::class), $this->isInstanceOf(MappingContext::class))
      ->willReturnCallback(function () use ($calls) {
        $calls->append('theme');
      });

    $mapping = $this->mapper()->map($this->paragraph([], 'article_video'), 'full');

    $this->assertSame(['plugin:article-video:full', 'module', 'theme'], $calls->getArrayCopy());
    $this->assertTrue($mapping->get('from_plugin'));
    $this->assertSame('custom', $mapping->getTemplate());
    $this->assertSame('article-video', $mapping->getComponent());
  }

  /**
   * An exception in a plugin propagates and the accumulator is drained.
   *
   * @covers ::map
   */
  public function testPluginExceptionPropagates(): void {
    $this->helper = $this->createMock(EntityHelper::class);
    $this->helper->method('beginCacheScope')->willReturn(new CacheScope(1, NULL));
    $this->helper->method('mapFields')->willReturn([]);
    $this->helper->expects($this->once())->method('endCacheScope')->with($this->isInstanceOf(CacheScope::class))->willReturn(new CacheableMetadata());
    $this->definition([]);
    $plugin = $this->createMock(ParagraphMapperInterface::class);
    $plugin->method('alter')->willThrowException(new \LogicException('bad plugin'));
    $manager = $this->createMock(ParagraphMapperManagerInterface::class);
    $manager->method('getMappersForComponent')->willReturn(['p' => $plugin]);
    $this->manager = $manager;

    $this->expectException(\LogicException::class);
    $this->mapper()->map($this->paragraph([]), 'default');
  }

  /**
   * Builds a real EntityHelper. Its accumulator is real state.
   */
  private function realHelper(): EntityHelper {
    return new EntityHelper(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(RouteMatchInterface::class),
      $this->createMock(LanguageManagerInterface::class),
      $this->createMock(EntityRepositoryInterface::class),
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(Connection::class),
      $this->createMock(CacheBackendInterface::class),
      $this->createMock(MenuLinkTreeInterface::class),
      $this->createMock(FileUrlGeneratorInterface::class),
      $this->createMock(LoggerChannelFactoryInterface::class),
      $this->createMock(RendererInterface::class),
      $this->createMock(DateFormatterInterface::class),
      $this->createMock(ImageFactory::class),
      $this->createMock(RequestStack::class),
      $this->createMock(MenuActiveTrailResolver::class),
      $this->createMock(TaxonomyTreeBuilder::class),
      $this->createMock(MenuTreeBuilder::class),
      $this->createMock(MediaArrayBuilder::class),
    );
  }

  /**
   * A plugin that adds tags to the helper's accumulator, like a media load.
   *
   * @param \Drupal\drupal_kit\Services\EntityHelper $helper
   *   The helper.
   * @param string[] $tags
   *   Tags to add when the plugin runs.
   * @param callable|null $after
   *   Called after the tags were added; may throw or map a child.
   */
  private function tagPlugin(EntityHelper $helper, array $tags, ?callable $after = NULL): ParagraphMapperInterface {
    return new class($helper, $tags, $after) implements ParagraphMapperInterface {

      /**
       * Constructs the stub.
       *
       * @param \Drupal\drupal_kit\Services\EntityHelper $helper
       *   The helper.
       * @param string[] $tags
       *   Tags to add.
       * @param callable|null $after
       *   Callback after adding.
       */
      public function __construct(private EntityHelper $helper, private array $tags, private $after) {}

      /**
       * {@inheritdoc}
       */
      public function alter(ParagraphMapping $mapping, MappingContext $context): void {
        $this->helper->addCacheTags($this->tags);
        if ($this->after) {
          ($this->after)();
        }
      }

    };
  }

  /**
   * Builds a mapper over a real helper with the given plugin.
   */
  private function mapperWith(EntityHelper $helper, ParagraphMapperInterface $plugin): ParagraphComponentMapper {
    $manager = $this->createMock(ParagraphMapperManagerInterface::class);
    $manager->method('getMappersForComponent')->willReturn(['p' => $plugin]);
    $this->manager = $manager;
    $this->definition([]);
    return $this->mapper($helper);
  }

  /**
   * Outer metadata survives a successful mapping and ours does not leak out.
   *
   * @covers ::map
   */
  public function testScopeKeepsOuterMetadataOnSuccess(): void {
    $helper = $this->realHelper();
    $helper->addCacheTags(['outer:1']);

    $mapping = $this->mapperWith($helper, $this->tagPlugin($helper, ['media:7']))->map($this->paragraph([]), 'default');

    $this->assertEqualsCanonicalizing(['paragraph:1', 'media:7'], $mapping->getCacheableMetadata()->getCacheTags());
    $this->assertNotContains('outer:1', $mapping->getCacheableMetadata()->getCacheTags());
    $left = $helper->collectCacheMetadata()->getCacheTags();
    $this->assertSame(['outer:1'], $left);
  }

  /**
   * Outer metadata survives a propagated exception, and ours is dropped.
   *
   * @covers ::map
   */
  public function testScopeKeepsOuterMetadataOnException(): void {
    $helper = $this->realHelper();
    $helper->addCacheTags(['outer:1']);
    $plugin = $this->tagPlugin($helper, ['media:7'], static function () {
      throw new \LogicException('boom');
    });
    $mapper = $this->mapperWith($helper, $plugin);

    try {
      $mapper->map($this->paragraph([]), 'default');
      $this->fail('Expected an exception.');
    }
    catch (\LogicException) {
      $this->addToAssertionCount(1);
    }

    $this->assertSame(['outer:1'], $helper->collectCacheMetadata()->getCacheTags());
  }

  /**
   * A mapping that runs inside a mapping keeps both scopes apart.
   *
   * @covers ::map
   */
  public function testNestedMappingScopes(): void {
    $helper = $this->realHelper();
    $helper->addCacheTags(['outer:1']);
    $inner_mapping = NULL;
    $holder = new \stdClass();
    $child = $this->paragraph([], 'contact', NULL, ['paragraph:2']);
    // Only the first call re-enters, or the child would map itself forever.
    $calls = 0;
    $plugin_reentrant = $this->tagPlugin($helper, ['media:parent'], function () use ($holder, &$inner_mapping, $child, $helper, &$calls) {
      if ($calls++ === 0) {
        $inner_mapping = $holder->mapper->map($child, 'default');
        $helper->addCacheTags(['after:child']);
      }
    });
    $mapper = $holder->mapper = $this->mapperWith($helper, $plugin_reentrant);

    $outer_mapping = $mapper->map($this->paragraph([]), 'default');
    $this->assertInstanceOf(ParagraphMapping::class, $inner_mapping);

    $this->assertEqualsCanonicalizing(['paragraph:2', 'media:parent'], $inner_mapping->getCacheableMetadata()->getCacheTags());
    $this->assertEqualsCanonicalizing(['paragraph:1', 'media:parent', 'after:child'], $outer_mapping->getCacheableMetadata()->getCacheTags());
    $this->assertSame(['outer:1'], $helper->collectCacheMetadata()->getCacheTags());
  }

  /**
   * An inner scope that a plugin leaves open is dropped, ours still ends.
   *
   * @covers ::map
   */
  public function testScopeLeftOpenByPluginIsAbandoned(): void {
    $helper = $this->realHelper();
    $helper->addCacheTags(['outer:1']);
    $plugin = $this->tagPlugin($helper, ['ours:before'], static function () use ($helper) {
      $helper->beginCacheScope();
      $helper->addCacheTags(['inner:lost']);
    });

    $mapping = $this->mapperWith($helper, $plugin)->map($this->paragraph([]), 'default');

    $tags = $mapping->getCacheableMetadata()->getCacheTags();
    $this->assertContains('ours:before', $tags);
    $this->assertNotContains('inner:lost', $tags);
    $this->assertNotContains('outer:1', $tags);
    $this->assertSame(['outer:1'], $helper->collectCacheMetadata()->getCacheTags());
  }

  /**
   * An exception with a scope left open still restores the outer content.
   *
   * @covers ::map
   */
  public function testExceptionWithScopeLeftOpenRestoresOuter(): void {
    $helper = $this->realHelper();
    $helper->addCacheTags(['outer:1']);
    $plugin = $this->tagPlugin($helper, ['ours:before'], static function () use ($helper) {
      $helper->beginCacheScope();
      $helper->addCacheTags(['inner:lost']);
      throw new \LogicException('boom');
    });
    $mapper = $this->mapperWith($helper, $plugin);

    try {
      $mapper->map($this->paragraph([]), 'default');
      $this->fail('Expected an exception.');
    }
    catch (\LogicException $e) {
      $this->assertSame('boom', $e->getMessage());
    }

    $this->assertSame(['outer:1'], $helper->collectCacheMetadata()->getCacheTags());
  }

}
