<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Unit\Paragraphs;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Config\Entity\ThirdPartySettingsInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
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
use Drupal\drupal_kit_paragraphs\Mapper\ComponentMapperFile;
use Drupal\drupal_kit_paragraphs\Mapper\ComponentMapperFileLoader;
use Drupal\drupal_kit_paragraphs\Mapper\ComponentMapperFileLoaderInterface;
use Drupal\drupal_kit_paragraphs\Mapping\MappingContext;
use Drupal\drupal_kit_paragraphs\Mapping\ParagraphMapping;
use Drupal\media\MediaInterface;
use Drupal\Tests\drupal_kit\Stubs\PublishedParagraphInterface;
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
use Symfony\Component\DependencyInjection\ContainerInterface as SymfonyContainer;

require_once dirname(__DIR__, 3) . '/fixtures/stubs/ParagraphInterface.php';
require_once dirname(__DIR__, 3) . '/fixtures/stubs/PublishedParagraphInterface.php';

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
   * The component file loader stub.
   */
  private ComponentMapperFileLoaderInterface&MockObject $loader;

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
    $this->loader = $this->createMock(ComponentMapperFileLoaderInterface::class);
    $this->loader->method('load')->willReturn(NULL);
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
  private function mapper(?EntityHelper $helper = NULL, ?ComponentMapperFileLoaderInterface $loader = NULL): ParagraphComponentMapper {
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($this->logger);
    return new ParagraphComponentMapper(
      $this->repository,
      $helper ?? $this->helper,
      $loader ?? $this->loader,
      $this->moduleHandler,
      $this->themeManager,
      $factory,
      $this->createMock(SymfonyContainer::class),
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
   * Lets the loader return a component file with the given steps.
   */
  private function useFile(?\Closure $alter, ?\Closure $replace = NULL): void {
    $this->loader = $this->createMock(ComponentMapperFileLoaderInterface::class);
    $this->loader->method('load')->willReturn(new ComponentMapperFile('/component/x.php', $alter, $replace));
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
    // A query field needs a PHP file, or the mapper renders the alert.
    $this->useFile(static function (): void {
    });
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
    $this->loader = $this->createMock(ComponentMapperFileLoaderInterface::class);
    $this->loader->expects($this->never())->method('load');
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
   * The file's alter runs after the generic rules and before the hooks.
   *
   * @covers ::build
   */
  public function testGenericThenFileAlterThenHooks(): void {
    $this->helper->method('mapFields')->willReturn(['phone' => '123']);
    $this->definition(['phone' => ['type' => 'text', 'role' => 'field']], 'article-video');
    $calls = new \ArrayObject();
    $this->useFile(static function (ParagraphMapping $mapping, MappingContext $context, EntityHelper $fields) use ($calls): void {
      // The generic rule already set phone.
      $calls->append('alter:' . $context->getComponent() . ':' . $context->getViewMode() . ':phone=' . $mapping->get('phone'));
      $mapping->set('from_file', TRUE)->setTemplate('custom');
    });

    $types = ['drupal_kit_paragraph_mapping', 'drupal_kit_paragraph_mapping_article_video'];
    $this->moduleHandler->expects($this->once())->method('alter')
      ->with($types, $this->isInstanceOf(ParagraphMapping::class), $this->isInstanceOf(MappingContext::class))
      ->willReturnCallback(function ($types, ParagraphMapping $mapping) use ($calls) {
        $calls->append('module:template=' . $mapping->getTemplate());
      });
    $this->themeManager->expects($this->once())->method('alter')
      ->with($types, $this->isInstanceOf(ParagraphMapping::class), $this->isInstanceOf(MappingContext::class))
      ->willReturnCallback(function () use ($calls) {
        $calls->append('theme');
      });

    $mapping = $this->mapper()->map($this->paragraph(['field_phone' => ['telephone']], 'article_video'), 'full');

    $this->assertSame(['alter:article-video:full:phone=123', 'module:template=custom', 'theme'], $calls->getArrayCopy());
    $this->assertTrue($mapping->get('from_file'));
    $this->assertSame('article-video', $mapping->getComponent());
  }

  /**
   * Each step is recorded as the writer of the keys it changed.
   *
   * @covers ::build
   * @covers ::recordOrigins
   */
  public function testOriginsNameTheStepsInOrder(): void {
    $this->helper->method('mapFields')->willReturn(['phone' => '123']);
    $this->definition(['phone' => ['type' => 'text', 'role' => 'field']], 'article-video');
    $this->useFile(static function (ParagraphMapping $mapping): void {
      $mapping->set('phone', '456')->set('from_file', TRUE)->remove('container');
    });
    $this->moduleHandler->method('alter')->willReturnCallback(static function ($types, ParagraphMapping $mapping) {
      $mapping->set('from_module', TRUE);
    });
    $this->themeManager->method('alter')->willReturnCallback(static function ($types, ParagraphMapping $mapping) {
      $mapping->set('from_module', 'theme wins');
    });

    $mapping = $this->mapper()->map($this->paragraph(['field_phone' => ['telephone']], 'article_video'), 'full');

    $this->assertSame([
      'container' => ['framework', 'file alter (removed)'],
      'phone' => ['definition', 'file alter'],
      'from_file' => ['file alter'],
      'from_module' => ['module hook', 'theme hook'],
    ], $mapping->getOrigins());
    $this->assertSame('/component/x.php', $mapping->getSources()['file']);
    $this->assertArrayHasKey('definition', $mapping->getSources());
  }

  /**
   * A replace step builds the mapping; the generic rules do not run.
   *
   * @covers ::build
   * @covers ::adopt
   */
  public function testReplaceStepReplacesGenericMapping(): void {
    $this->helper->expects($this->never())->method('mapFields');
    $this->definition(['phone' => ['type' => 'text', 'role' => 'field']], 'replacing');
    $calls = new \ArrayObject();
    $this->useFile(
      static function (ParagraphMapping $mapping) use ($calls): void {
        $calls->append('alter:' . $mapping->get('built'));
        $mapping->set('altered', TRUE);
      },
      static function (ParagraphInterface $paragraph, string $view_mode, EntityHelper $fields, MappingContext $context) use ($calls): ParagraphMapping {
        $calls->append('context:' . $context->getComponent());
        $calls->append('replace:' . $view_mode);
        return (new ParagraphMapping())->set('built', 'by-replace')->setTemplate('other')
          ->addDiagnostic('note')
          ->addCacheableDependency((new CacheableMetadata())->setCacheTags(['replace:tag']));
      },
    );

    $mapping = $this->mapper()->map($this->paragraph([], 'replacing'), 'full');

    $this->assertSame(['context:replacing', 'replace:full', 'alter:by-replace'], $calls->getArrayCopy());
    // The framework field stays, the replace data merges over it.
    $this->assertSame(['container' => '', 'built' => 'by-replace', 'altered' => TRUE], $mapping->getData());
    $this->assertSame('replacing', $mapping->getComponent());
    $this->assertSame('other', $mapping->getTemplate());
    $this->assertSame(['note'], $mapping->getDiagnostics());
    $this->assertContains('replace:tag', $mapping->getCacheableMetadata()->getCacheTags());
  }

  /**
   * A replace step that returns something else is a programming error.
   *
   * @covers ::build
   */
  public function testReplaceStepWithWrongReturnPropagates(): void {
    $this->definition([], 'replacing');
    $this->useFile(NULL, static fn () => 'nope');

    $this->expectException(\UnexpectedValueException::class);
    $this->mapper()->map($this->paragraph([], 'replacing'), 'full');
  }

  /**
   * An exception in the file's code propagates and the accumulator is drained.
   *
   * @covers ::map
   */
  public function testFileExceptionPropagates(): void {
    $this->helper = $this->createMock(EntityHelper::class);
    $this->helper->method('beginCacheScope')->willReturn(new CacheScope(1, NULL));
    $this->helper->method('mapFields')->willReturn([]);
    $this->helper->expects($this->once())->method('endCacheScope')->with($this->isInstanceOf(CacheScope::class))->willReturn(new CacheableMetadata());
    $this->definition([]);
    $this->useFile(static function (): void {
      throw new \LogicException('bad file');
    });

    $this->expectException(\LogicException::class);
    $this->mapper()->map($this->paragraph([]), 'default');
  }

  /**
   * A malformed component file renders the alert and logs an error.
   *
   * @covers ::map
   */
  public function testMalformedFileRendersAlert(): void {
    $this->definition([]);
    $this->loader = $this->createMock(ComponentMapperFileLoaderInterface::class);
    $this->loader->method('load')->willThrowException(new InvalidComponentDefinitionException('syntax error'));
    $this->logger->expects($this->once())->method('error')->with($this->stringContains('malformed'), $this->arrayHasKey('@message'));

    $mapping = $this->mapper()->map($this->paragraph([], 'contact'), 'default');

    $this->assertAlert($mapping, 'contact');
  }

  /**
   * Builds a real EntityHelper. Its accumulator is real state.
   */
  private function realHelper(?EntityRepositoryInterface $repository = NULL, ?MediaArrayBuilder $media = NULL, ?RendererInterface $renderer = NULL): EntityHelper {
    return new EntityHelper(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(RouteMatchInterface::class),
      $this->createMock(LanguageManagerInterface::class),
      $repository ?? $this->createMock(EntityRepositoryInterface::class),
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(Connection::class),
      $this->createMock(CacheBackendInterface::class),
      $this->createMock(MenuLinkTreeInterface::class),
      $this->createMock(FileUrlGeneratorInterface::class),
      $this->createMock(LoggerChannelFactoryInterface::class),
      $renderer ?? $this->createMock(RendererInterface::class),
      $this->createMock(DateFormatterInterface::class),
      $this->createMock(ImageFactory::class),
      $this->createMock(RequestStack::class),
      $this->createMock(MenuActiveTrailResolver::class),
      $this->createMock(TaxonomyTreeBuilder::class),
      $this->createMock(MenuTreeBuilder::class),
      $media ?? $this->createMock(MediaArrayBuilder::class),
    );
  }

  /**
   * Builds an alter step that adds tags to the accumulator, like a media load.
   *
   * @param \Drupal\drupal_kit\Services\EntityHelper $helper
   *   The helper.
   * @param string[] $tags
   *   Tags to add when the step runs.
   * @param callable|null $after
   *   Called after the tags were added; may throw or map a child.
   */
  private function tagStep(EntityHelper $helper, array $tags, ?callable $after = NULL): \Closure {
    return static function (ParagraphMapping $mapping, MappingContext $context, EntityHelper $fields) use ($helper, $tags, $after): void {
      $helper->addCacheTags($tags);
      if ($after) {
        $after();
      }
    };
  }

  /**
   * Builds a mapper over a real helper with the given alter step.
   */
  private function mapperWith(EntityHelper $helper, \Closure $alter): ParagraphComponentMapper {
    $this->useFile($alter);
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

    $mapping = $this->mapperWith($helper, $this->tagStep($helper, ['media:7']))->map($this->paragraph([]), 'default');

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
    $step = $this->tagStep($helper, ['media:7'], static function () {
      throw new \LogicException('boom');
    });
    $mapper = $this->mapperWith($helper, $step);

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
    $step_reentrant = $this->tagStep($helper, ['media:parent'], function () use ($holder, &$inner_mapping, $child, $helper, &$calls) {
      if ($calls++ === 0) {
        $inner_mapping = $holder->mapper->map($child, 'default');
        $helper->addCacheTags(['after:child']);
      }
    });
    $mapper = $holder->mapper = $this->mapperWith($helper, $step_reentrant);

    $outer_mapping = $mapper->map($this->paragraph([]), 'default');
    $this->assertInstanceOf(ParagraphMapping::class, $inner_mapping);

    $this->assertEqualsCanonicalizing(['paragraph:2', 'media:parent'], $inner_mapping->getCacheableMetadata()->getCacheTags());
    $this->assertEqualsCanonicalizing(['paragraph:1', 'media:parent', 'after:child'], $outer_mapping->getCacheableMetadata()->getCacheTags());
    $this->assertSame(['outer:1'], $helper->collectCacheMetadata()->getCacheTags());
  }

  /**
   * On success, a scope a plugin leaves open keeps its metadata in the mapping.
   *
   * @covers ::map
   * @covers ::closeLeakedScopes
   */
  public function testLeakedScopeOnSuccessIsKept(): void {
    $helper = $this->realHelper();
    $helper->addCacheTags(['outer:1']);
    $step = $this->tagStep($helper, ['ours:before'], static function () use ($helper) {
      $helper->beginCacheScope();
      $helper->addCacheTags(['inner:kept']);
    });
    $this->logger->expects($this->once())->method('warning')
      ->with($this->stringContains('left @count cache scope'), $this->callback(
        static fn (array $args) => $args['@count'] === 1 && str_contains($args['@source'], 'alter step'),
      ));

    $mapping = $this->mapperWith($helper, $step)->map($this->paragraph([]), 'default');

    $tags = $mapping->getCacheableMetadata()->getCacheTags();
    $this->assertContains('ours:before', $tags);
    $this->assertContains('inner:kept', $tags);
    $this->assertNotContains('outer:1', $tags);
    $this->assertSame(['outer:1'], $helper->collectCacheMetadata()->getCacheTags());
  }

  /**
   * Two nested leaked scopes keep their metadata on success.
   *
   * @covers ::map
   */
  public function testNestedLeakedScopesOnSuccessAreKept(): void {
    $helper = $this->realHelper();
    $helper->addCacheTags(['outer:1']);
    $step = $this->tagStep($helper, ['ours:before'], static function () use ($helper) {
      $helper->beginCacheScope();
      $helper->addCacheTags(['inner:one']);
      $helper->beginCacheScope();
      $helper->addCacheTags(['inner:two']);
    });
    $this->logger->expects($this->once())->method('warning')
      ->with($this->anything(), $this->callback(static fn (array $args) => $args['@count'] === 2));

    $mapping = $this->mapperWith($helper, $step)->map($this->paragraph([]), 'default');

    $this->assertEqualsCanonicalizing(
      ['paragraph:1', 'ours:before', 'inner:one', 'inner:two'],
      $mapping->getCacheableMetadata()->getCacheTags(),
    );
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
    $step = $this->tagStep($helper, ['ours:before'], static function () use ($helper) {
      $helper->beginCacheScope();
      $helper->addCacheTags(['inner:lost']);
      $helper->beginCacheScope();
      $helper->addCacheTags(['inner:lost:two']);
      throw new \LogicException('boom');
    });
    $mapper = $this->mapperWith($helper, $step);
    $this->logger->expects($this->never())->method('warning');

    try {
      $mapper->map($this->paragraph([]), 'default');
      $this->fail('Expected an exception.');
    }
    catch (\LogicException $e) {
      $this->assertSame('boom', $e->getMessage());
    }

    // The leaked scopes' metadata is dropped, and no warning is logged: the
    // exception is the news.
    $this->assertSame(['outer:1'], $helper->collectCacheMetadata()->getCacheTags());
  }

  /**
   * A query field without a PHP file renders the alert and logs.
   *
   * @covers ::map
   * @covers ::findQueryFields
   */
  public function testQueryFieldWithoutFileRendersAlert(): void {
    $this->helper->expects($this->never())->method('mapFields');
    $this->definition([
      'title' => ['type' => 'text', 'role' => 'field'],
      'items' => ['type' => 'list', 'role' => 'query'],
      'group' => ['type' => 'object', 'role' => 'field', 'fields' => ['deep' => ['type' => 'text', 'role' => 'query']]],
    ], 'article-featured');
    $this->logger->expects($this->once())->method('warning')
      ->with($this->stringContains('needs a PHP file'), $this->callback(
        static fn (array $args) => $args['@component'] === 'article-featured' && $args['@fields'] === 'items, group.deep',
      ));
    $this->moduleHandler->expects($this->never())->method('alter');

    $mapping = $this->mapper()->map($this->paragraph([], 'article_featured'), 'default');

    $this->assertSame('alert', $mapping->getTemplate());
    $this->assertSame('error', $mapping->get('type'));
    $this->assertStringContainsString('PHP mapper file', (string) $mapping->get('message'));
    // The alert is decided before any field is read: only the one note.
    $this->assertSame(
      ['The article-featured component has query fields (items, group.deep) and no article-featured.php file.'],
      $mapping->getDiagnostics(),
    );
    $this->assertContains('theme', $mapping->toRenderArray()['#cache']['contexts']);
  }

  /**
   * A colocated PHP file suppresses the alert.
   *
   * @covers ::map
   */
  public function testQueryFieldWithFileRendersComponent(): void {
    $this->helper->method('mapFields')->willReturn([]);
    $this->definition(['items' => ['type' => 'list', 'role' => 'query']], 'article-featured');
    $ran = new \ArrayObject();
    $this->useFile(static function () use ($ran): void {
      $ran->append(1);
    });
    $this->logger->expects($this->once())->method('warning')
      ->with($this->stringContains('declares no list cache tag'), $this->anything());

    $mapping = $this->mapper()->map($this->paragraph([], 'article_featured'), 'default');

    $this->assertSame('article-featured', $mapping->getTemplate());
    $this->assertCount(1, $ran);
    $this->assertNotContains(
      'The article-featured component has query fields (items) and no article-featured.php file.',
      $mapping->getDiagnostics(),
    );
    // The file declared no list tag, so the mapper says so.
    $this->assertStringContainsString('declares no list cache tag', implode(' ', $mapping->getDiagnostics()));
  }

  /**
   * A query component that declares its list dependency raises no warning.
   *
   * @covers ::map
   * @covers ::warnWithoutListDependency
   */
  public function testQueryComponentWithListDependencyIsQuiet(): void {
    $this->helper->method('mapFields')->willReturn([]);
    $this->definition(['items' => ['type' => 'list', 'role' => 'query']], 'article-featured');
    $this->useFile(static function (ParagraphMapping $mapping): void {
      $mapping->addListDependency('node', 'article');
    });
    $this->logger->expects($this->never())->method('warning');

    $mapping = $this->mapper()->map($this->paragraph([], 'article_featured'), 'default');

    $this->assertSame([], $mapping->getDiagnostics());
    $this->assertContains('node_list:article', $mapping->getCacheableMetadata()->getCacheTags());
    $this->assertContains('user.node_grants:view', $mapping->getCacheableMetadata()->getCacheContexts());
  }

  /**
   * A component without query fields is never checked for a list tag.
   *
   * @covers ::map
   * @covers ::warnWithoutListDependency
   */
  public function testPlainComponentNeedsNoListDependency(): void {
    $this->helper->method('mapFields')->willReturn([]);
    $this->definition(['phone' => ['type' => 'text', 'role' => 'field']], 'contact');
    $this->useFile(static function (): void {});
    $this->logger->expects($this->never())->method('warning');

    $diagnostics = $this->mapper()->map($this->paragraph([], 'contact'), 'default')->getDiagnostics();
    $this->assertStringNotContainsString('list cache tag', implode(' ', $diagnostics));
  }

  /**
   * Framework keys and parent fields never trigger the alert.
   *
   * The `content` component marks `container` as a query field and has
   * caller-supplied parent fields, and it needs no PHP file.
   *
   * @covers ::findQueryFields
   */
  public function testFrameworkQueryAndParentFieldsNeedNoFile(): void {
    $this->helper->method('mapFields')->willReturn([]);
    $this->definition([
      'container' => ['type' => 'text', 'role' => 'query'],
      'name' => ['type' => 'text', 'role' => 'parent'],
    ], 'content');

    $mapping = $this->mapper()->map($this->paragraph([], 'content'), 'default');

    $this->assertSame('content', $mapping->getTemplate());
  }

  /**
   * Builds a paragraph over real field lists, for the shape tests.
   *
   * @param array<string, array<string, mixed>> $fields
   *   Field name to: type, cardinality, handler, values.
   */
  private function stateful(array $fields): ParagraphInterface&MockObject {
    $paragraph = $this->createMock(ParagraphInterface::class);
    $paragraph->method('bundle')->willReturn('quote_image');
    $paragraph->method('id')->willReturn('1');
    $paragraph->method('getCacheTags')->willReturn(['paragraph:1']);
    $paragraph->method('getCacheContexts')->willReturn([]);
    $paragraph->method('getCacheMaxAge')->willReturn(-1);
    $paragraph->method('hasField')->willReturnCallback(static fn ($name) => isset($fields[$name]));
    $paragraph->method('getFieldDefinition')->willReturnCallback(function ($name) use ($fields) {
      $storage = $this->createMock(FieldStorageDefinitionInterface::class);
      $storage->method('getCardinality')->willReturn($fields[$name]['cardinality']);
      $definition = $this->createMock(FieldDefinitionInterface::class);
      $definition->method('getType')->willReturn($fields[$name]['type']);
      $definition->method('getSettings')->willReturn(['handler' => $fields[$name]['handler'] ?? '']);
      $definition->method('getFieldStorageDefinition')->willReturn($storage);
      return $definition;
    });
    $paragraph->method('get')->willReturnCallback(function ($name) use ($fields) {
      $values = $fields[$name]['values'];
      $items = [];
      foreach ($values as $value) {
        $items[] = new class($value) {

          /**
           * The text value.
           */
          public mixed $value;

          /**
           * The referenced entity.
           */
          public mixed $entity;

          /**
           * The text format.
           */
          public mixed $format = 'plain_text';

          /**
           * Constructs the item.
           */
          public function __construct(mixed $value) {
            $this->value = $value;
            $this->entity = $value;
          }

        };
      }
      if (($fields[$name]['type'] ?? '') === 'entity_reference_revisions') {
        $list = $this->createMock(EntityReferenceFieldItemListInterface::class);
        $list->method('referencedEntities')->willReturn($values);
        return $list;
      }
      $list = $this->createMockForIntersectionOfInterfaces([FieldItemListInterface::class, \IteratorAggregate::class]);
      $list->method('getIterator')->willReturn(new \ArrayIterator($items));
      return $list;
    });
    return $paragraph;
  }

  /**
   * Makes a media entity stub.
   */
  private function media(string $bundle = 'image', string $tag = 'media:1'): MediaInterface&MockObject {
    $media = $this->createMock(MediaInterface::class);
    $media->method('bundle')->willReturn($bundle);
    $media->method('isPublished')->willReturn(TRUE);
    $media->method('getCacheTags')->willReturn([$tag]);
    $media->method('getCacheContexts')->willReturn([]);
    $media->method('getCacheMaxAge')->willReturn(-1);
    return $media;
  }

  /**
   * Maps one leaf of a real helper over the given field.
   *
   * @param array<string, mixed> $node
   *   The definition node.
   * @param array<string, mixed> $field
   *   The field spec for stateful().
   */
  private function mapLeaf(array $node, array $field): mixed {
    $repository = $this->createMock(EntityRepositoryInterface::class);
    $repository->method('getTranslationFromContext')->willReturnArgument(0);
    $builder = $this->createMock(MediaArrayBuilder::class);
    $counter = 0;
    $builder->method('buildImage')->willReturnCallback(static function () use (&$counter) {
      $counter++;
      return [['src' => "img$counter.avif"]];
    });
    $helper = $this->realHelper($repository, $builder);
    // A stub cannot be re-configured, and the helper maps several shapes.
    $this->repository = $this->createMock(ComponentDefinitionRepositoryInterface::class);
    $this->definition(['image' => $node + ['role' => 'field']]);
    $paragraph = $this->stateful(['field_image' => $field]);

    return $this->mapper($helper)->map($paragraph, 'default')->get('image');
  }

  /**
   * Media on a many-value field, YAML multiple: a list of media, each a list.
   *
   * @covers ::mapNodes
   */
  public function testMediaManyValuesMultipleTrueIsMediaList(): void {
    $field = ['type' => 'entity_reference', 'cardinality' => -1, 'handler' => 'default:media'];
    $node = ['type' => 'media', 'multiple' => TRUE];
    $one = $this->mapLeaf($node, $field + ['values' => [$this->media()]]);
    $two = $this->mapLeaf($node, $field + ['values' => [$this->media(), $this->media()]]);

    // The gallery shape: one entry per media, each entry the image variants.
    $this->assertSame([[['src' => 'img1.avif']]], $one);
    $this->assertSame([[['src' => 'img1.avif']], [['src' => 'img2.avif']]], $two);
  }

  /**
   * An explicit `multiple: false` gives the bare media, as the old display did.
   *
   * @covers ::mapNodes
   */
  public function testMediaManyValuesMultipleFalseIsTheBareMedia(): void {
    $field = ['type' => 'entity_reference', 'cardinality' => -1, 'handler' => 'default:media'];

    $one = $this->mapLeaf(['type' => 'media', 'multiple' => FALSE], $field + ['values' => [$this->media()]]);
    $both = ['values' => [$this->media(), $this->media()]];
    $two = $this->mapLeaf(['type' => 'media', 'multiple' => FALSE], $field + $both);

    $this->assertSame([['src' => 'img1.avif']], $one);
    // More than one value stays a list, as the formatter returns it.
    $this->assertSame([[['src' => 'img1.avif']], [['src' => 'img2.avif']]], $two);
  }

  /**
   * A one-value media field is the bare media, with or without `multiple`.
   *
   * @covers ::mapNodes
   */
  public function testMediaSingleValueIsTheBareMedia(): void {
    $field = ['type' => 'entity_reference', 'cardinality' => 1, 'handler' => 'default:media'];

    foreach ([FALSE, TRUE, NULL] as $multiple) {
      $node = ['type' => 'media'] + ($multiple === NULL ? [] : ['multiple' => $multiple]);
      $this->assertSame([['src' => 'img1.avif']], $this->mapLeaf($node, $field + ['values' => [$this->media()]]));
    }
  }

  /**
   * A many-value text field is a list, a one-value field the bare string.
   *
   * @covers ::mapNodes
   */
  public function testTextShapes(): void {
    $many = ['type' => 'string', 'cardinality' => -1];
    $this->assertSame(['x', 'y'], $this->mapLeaf(['type' => 'text'], $many + ['values' => ['x', 'y']]));
    $this->assertSame(['x'], $this->mapLeaf(['type' => 'text'], $many + ['values' => ['x']]));
    $this->assertSame('x', $this->mapLeaf(['type' => 'text', 'multiple' => FALSE], $many + ['values' => ['x']]));
    $single = ['type' => 'string', 'cardinality' => 1, 'values' => ['x']];
    $this->assertSame('x', $this->mapLeaf(['type' => 'text'], $single));
  }

  /**
   * A many-value reference with child fields is a list of mapped children.
   *
   * @covers ::mapChildren
   */
  public function testReferenceChildrenShapes(): void {
    $repository = $this->createMock(EntityRepositoryInterface::class);
    $repository->method('getTranslationFromContext')->willReturnArgument(0);
    $helper = $this->realHelper($repository);
    $makeChild = function (string $label) {
      $child = $this->createMock(PublishedParagraphInterface::class);
      $child->method('isPublished')->willReturn(TRUE);
      $child->method('getCacheTags')->willReturn(['child']);
      $child->method('getCacheContexts')->willReturn([]);
      $child->method('getCacheMaxAge')->willReturn(-1);
      $child->method('hasField')->willReturnCallback(static fn ($name) => $name === 'field_label');
      $child->method('getFieldDefinition')->willReturnCallback(function () {
        $storage = $this->createMock(FieldStorageDefinitionInterface::class);
        $storage->method('getCardinality')->willReturn(1);
        $definition = $this->createMock(FieldDefinitionInterface::class);
        $definition->method('getType')->willReturn('string');
        $definition->method('getFieldStorageDefinition')->willReturn($storage);
        return $definition;
      });
      $item = new \stdClass();
      $item->value = $label;
      $list = $this->createMockForIntersectionOfInterfaces([FieldItemListInterface::class, \IteratorAggregate::class]);
      $list->method('getIterator')->willReturn(new \ArrayIterator([$item]));
      $child->method('get')->willReturn($list);
      return $child;
    };
    $this->definition([
      'items' => [
        'type' => 'list',
        'role' => 'field',
        'drupal' => ['field' => 'field_paragraphs'],
        'fields' => ['label' => ['type' => 'text']],
      ],
    ], 'numbers');
    $paragraph = $this->stateful([
      'field_paragraphs' => [
        'type' => 'entity_reference_revisions',
        'cardinality' => -1,
        'values' => [$makeChild('A'), $makeChild('B')],
      ],
    ]);

    $items = $this->mapper($helper)->map($paragraph, 'default')->get('items');

    $this->assertSame([['label' => 'A'], ['label' => 'B']], $items);
  }

  /**
   * Runs the committed article-video file end to end.
   *
   * @param array<int, mixed> $image_media
   *   The values of the image field.
   *
   * @return array{0: \Drupal\drupal_kit_paragraphs\Mapping\ParagraphMapping, 1: \Drupal\drupal_kit\Services\EntityHelper}
   *   The mapping and the helper, to check what is left in the accumulator.
   */
  private function runArticleVideo(array $image_media): array {
    $repository = $this->createMock(ComponentDefinitionRepositoryInterface::class);
    $repository->method('isValidComponentName')->willReturn(TRUE);
    $repository->method('loadForBundle')->willReturn(new ComponentDefinition(
      'article-video',
      'theme_active/component/article-video/article-video.yaml',
      ['fields' => []],
    ));
    $this->repository = $repository;
    $loader = new ComponentMapperFileLoader($repository, dirname(__DIR__, 3) . '/fixtures/component_files');

    $entity_repository = $this->createMock(EntityRepositoryInterface::class);
    $entity_repository->method('getTranslationFromContext')->willReturnArgument(0);
    $builder = $this->createMock(MediaArrayBuilder::class);
    $embed = ['iframe' => 'https://video.example/embed/1', 'image' => [['src' => 'thumb.jpg']]];
    $builder->method('buildRemoteVideo')->willReturn($embed);
    $builder->method('buildImage')->willReturn([['src' => 'poster.avif']]);
    $helper = $this->realHelper($entity_repository, $builder);
    $helper->addCacheTags(['outer:1']);

    $single = ['type' => 'entity_reference', 'cardinality' => 1, 'handler' => 'default:media'];
    $paragraph = $this->stateful([
      'field_media' => $single + ['values' => [$this->media('remote_video', 'media:video')]],
      'field_image' => $single + ['values' => $image_media],
    ]);

    return [$this->mapper($helper, $loader)->map($paragraph, 'default'), $helper];
  }

  /**
   * The article-video file maps video and poster, and leaks no metadata.
   *
   * @covers ::map
   */
  public function testArticleVideoFileEndToEnd(): void {
    [$mapping, $helper] = $this->runArticleVideo([$this->media('image', 'media:poster')]);

    $this->assertSame('https://video.example/embed/1', $mapping->get('video'));
    $this->assertSame([['src' => 'poster.avif']], $mapping->get('image'));
    $tags = $mapping->getCacheableMetadata()->getCacheTags();
    $this->assertContains('media:video', $tags);
    $this->assertContains('media:poster', $tags);
    $this->assertNotContains('outer:1', $tags);
    // The file leaked nothing: the outer accumulator is what it was.
    $this->assertSame(['outer:1'], $helper->collectCacheMetadata()->getCacheTags());
  }

  /**
   * Without a poster field value, the thumbnail of the media is the image.
   *
   * @covers ::map
   */
  public function testArticleVideoFileFallsBackToTheThumbnail(): void {
    [$mapping, $helper] = $this->runArticleVideo([]);

    $this->assertSame('https://video.example/embed/1', $mapping->get('video'));
    $this->assertSame([['src' => 'thumb.jpg']], $mapping->get('image'));
    $this->assertSame(['outer:1'], $helper->collectCacheMetadata()->getCacheTags());
  }

  /**
   * The real replacing.php fixture keeps the framework fields.
   *
   * The file is loaded by the real loader, not a stub. Its replace step sets
   * only `built`; the framework keys come from the mapper and survive.
   *
   * @covers ::build
   * @covers ::adopt
   */
  public function testRealReplaceFileKeepsFrameworkFields(): void {
    $repository = $this->createMock(ComponentDefinitionRepositoryInterface::class);
    $repository->method('isValidComponentName')->willReturn(TRUE);
    $repository->method('loadForBundle')->willReturn(new ComponentDefinition(
      'replacing',
      'theme_active/component/replacing/replacing.yaml',
      ['fields' => ['never' => ['type' => 'text', 'role' => 'field']]],
    ));
    $this->repository = $repository;
    $loader = new ComponentMapperFileLoader($repository, dirname(__DIR__, 3) . '/fixtures/component_files');
    $string = ['type' => 'string', 'cardinality' => 1];
    $paragraph = $this->stateful([
      'field_title' => $string + ['values' => ['Title']],
      'field_wrapper_id' => $string + ['values' => ['anchor']],
      'field_wrapper_classes' => $string + ['values' => ['a b']],
      'field_never' => $string + ['values' => ['generic rule ran']],
    ]);
    $helper = $this->realHelper();

    $mapping = $this->mapper($helper, $loader)->map($paragraph, 'default');

    $data = $mapping->getData();
    $this->assertSame('', $data['container']);
    $this->assertSame('Title', $data['heading']['title']);
    $this->assertSame('anchor', $data['wrapper_id']);
    $this->assertSame('a b', $data['wrapper_classes']);
    $this->assertSame('by replace', $data['built']);
    $this->assertTrue($data['altered']);
    // The generic rules did not run.
    $this->assertArrayNotHasKey('never', $data);
  }

  /**
   * Maps a heading definition against a real helper and returns the data.
   *
   * @param array<string, mixed> $fields
   *   The field specs for stateful().
   * @param string $renderer_output
   *   What the renderer returns for processed text.
   *
   * @return array<string, mixed>
   *   The mapping data.
   */
  private function mapHeading(array $fields, string $renderer_output = '<p>Perex</p>'): array {
    $renderer = $this->createMock(RendererInterface::class);
    $renderer->method('renderInIsolation')->willReturn($renderer_output);
    $this->repository = $this->createMock(ComponentDefinitionRepositoryInterface::class);
    $this->definition([
      'heading' => [
        'type' => 'object',
        'role' => 'field',
        'fields' => [
          'title' => ['type' => 'text', 'role' => 'field'],
          'perex' => ['type' => 'richtext', 'role' => 'field'],
        ],
      ],
      'spacing' => ['type' => 'select', 'role' => 'field'],
    ], 'article-list');

    return $this->mapper($this->realHelper(NULL, NULL, $renderer))->map($this->stateful($fields), 'default')->getData();
  }

  /**
   * An empty title and perex create no heading, as the old display did.
   *
   * @covers ::mapNodes
   * @covers ::mapFrameworkFields
   */
  public function testEmptyHeadingFieldsCreateNoHeading(): void {
    $string = ['type' => 'string', 'cardinality' => 1];
    $data = $this->mapHeading([
      'field_title' => $string + ['values' => []],
      'field_perex' => ['type' => 'text_long', 'cardinality' => 1, 'values' => []],
      'field_spacing' => ['type' => 'list_string', 'cardinality' => 1, 'values' => []],
    ]);

    $this->assertArrayNotHasKey('heading', $data);
    $this->assertArrayNotHasKey('spacing', $data);
    // The one key the old display always set.
    $this->assertSame('', $data['container']);
  }

  /**
   * A paragraph type with no title field at all creates no heading.
   *
   * @covers ::mapNodes
   */
  public function testMissingHeadingFieldsCreateNoHeading(): void {
    $data = $this->mapHeading([]);

    $this->assertArrayNotHasKey('heading', $data);
  }

  /**
   * A non-empty title keeps the heading.
   *
   * @covers ::mapNodes
   */
  public function testNonEmptyTitleKeepsHeading(): void {
    $string = ['type' => 'string', 'cardinality' => 1];
    $data = $this->mapHeading([
      'field_title' => $string + ['values' => ['Title']],
      'field_perex' => ['type' => 'text_long', 'cardinality' => 1, 'values' => []],
    ]);

    $this->assertSame(['title' => 'Title'], $data['heading']);
  }

  /**
   * A heading with only a perex has only the perex.
   *
   * @covers ::mapNodes
   */
  public function testHeadingWithOnlyPerex(): void {
    $string = ['type' => 'string', 'cardinality' => 1];
    $data = $this->mapHeading([
      'field_title' => $string + ['values' => []],
      'field_perex' => ['type' => 'text_long', 'cardinality' => 1, 'values' => ['x']],
    ]);

    $this->assertSame(['perex' => '<p>Perex</p>'], $data['heading']);
  }

}
