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
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\drupal_kit\Component\ComponentDefinition;
use Drupal\drupal_kit\Component\ComponentDefinitionRepositoryInterface;
use Drupal\drupal_kit\Services\EntityHelper;
use Drupal\drupal_kit_paragraphs\Mapper\ParagraphComponentMapper;
use Drupal\drupal_kit_paragraphs\Mapper\ParagraphMapperInterface;
use Drupal\drupal_kit_paragraphs\Mapper\ParagraphMapperManagerInterface;
use Drupal\drupal_kit_paragraphs\Mapping\MappingContext;
use Drupal\drupal_kit_paragraphs\Mapping\ParagraphMapping;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;
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
    \Drupal::setContainer($container);

    $this->repository = $this->createMock(ComponentDefinitionRepositoryInterface::class);
    $this->helper = $this->createMock(EntityHelper::class);
    $this->helper->method('collectCacheMetadata')->willReturn(new CacheableMetadata());
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
  private function mapper(): ParagraphComponentMapper {
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($this->logger);
    return new ParagraphComponentMapper(
      $this->repository,
      $this->helper,
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
    $this->repository->method('loadForBundle')->willReturn(new ComponentDefinition($name, "component/$name/$name.yaml", ['fields' => $fields]));
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
   * A missing definition gives an empty mapping and a logged warning.
   *
   * @covers ::map
   */
  public function testMissingDefinitionGivesEmptyMapping(): void {
    $this->repository->method('loadForBundle')->willReturn(NULL);
    $this->logger->expects($this->once())->method('warning');
    $this->helper->expects($this->never())->method('mapFields');
    $this->moduleHandler->expects($this->never())->method('alter');

    $mapping = $this->mapper()->map($this->paragraph([]), 'default');

    $this->assertTrue($mapping->isEmpty());
    $this->assertSame([], $mapping->getData());
    $this->assertSame(['paragraph:1'], $mapping->getCacheableMetadata()->getCacheTags());
    $this->assertArrayNotHasKey('#theme', $mapping->toRenderArray());
  }

  /**
   * The cacheability of the paragraph and of the helper both end up on the map.
   *
   * @covers ::map
   */
  public function testCacheabilityMerges(): void {
    $this->helper = $this->createMock(EntityHelper::class);
    $this->helper->expects($this->once())->method('collectCacheMetadata')
      ->willReturn((new CacheableMetadata())->setCacheTags(['media:7'])->setCacheContexts(['languages:language_content'])->setCacheMaxAge(120));
    $this->definition([]);

    $metadata = $this->mapper()->map($this->paragraph([]), 'default')->getCacheableMetadata();

    $this->assertEqualsCanonicalizing(['paragraph:1', 'media:7'], $metadata->getCacheTags());
    $this->assertEqualsCanonicalizing(['languages:language_content', 'theme'], $metadata->getCacheContexts());
    $this->assertSame(120, $metadata->getCacheMaxAge());
  }

  /**
   * A failure drains the shared accumulator and returns an empty mapping.
   *
   * @covers ::map
   */
  public function testFailureDrainsAccumulatorAndReturnsEmpty(): void {
    $this->helper = $this->createMock(EntityHelper::class);
    $this->helper->expects($this->once())->method('collectCacheMetadata')
      ->willReturn((new CacheableMetadata())->setCacheTags(['media:7']));
    $this->helper->method('getTextField')->willThrowException(new \RuntimeException('boom'));
    $this->definition([]);
    $this->logger->expects($this->once())->method('error')->with($this->stringContains('failed'), $this->anything());

    $mapping = $this->mapper()->map($this->paragraph([]), 'default');

    $this->assertTrue($mapping->isEmpty());
    $this->assertSame([], $mapping->getData());
    // The tags of what the helper already loaded stay, so the (empty) output
    // still invalidates correctly.
    $this->assertEqualsCanonicalizing(['paragraph:1', 'media:7'], $mapping->getCacheableMetadata()->getCacheTags());
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
    $manager->expects($this->once())->method('getMappersForComponent')->with('article-video')->willReturn(['p' => $plugin]);
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
   * An exception in a plugin gives an empty mapping and no partial output.
   *
   * @covers ::map
   */
  public function testPluginExceptionGivesEmptyMapping(): void {
    $this->helper->method('mapFields')->willReturn([]);
    $this->definition([]);
    $plugin = $this->createMock(ParagraphMapperInterface::class);
    $plugin->method('alter')->willThrowException(new \LogicException('bad plugin'));
    $manager = $this->createMock(ParagraphMapperManagerInterface::class);
    $manager->method('getMappersForComponent')->willReturn(['p' => $plugin]);
    $this->manager = $manager;
    $this->logger->expects($this->once())->method('error');

    $mapping = $this->mapper()->map($this->paragraph([]), 'default');

    $this->assertTrue($mapping->isEmpty());
    $this->assertSame([], $mapping->getData());
  }

}
