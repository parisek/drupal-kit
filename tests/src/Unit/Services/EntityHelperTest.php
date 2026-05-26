<?php

namespace Drupal\Tests\custom_components\Unit\Services;

use Drupal\custom_components\Services\EntityHelper;
use Drupal\custom_components\Services\MediaArrayBuilder;
use Drupal\custom_components\Services\MenuTreeBuilder;
use Drupal\custom_components\Services\TaxonomyTreeBuilder;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\Url;
use Drupal\custom_components\Services\MenuActiveTrailResolver;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\taxonomy\TermStorageInterface;
use Drupal\comment\CommentInterface;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the EntityHelper service.
 *
 * @coversDefaultClass \Drupal\custom_components\Services\EntityHelper
 * @group custom_components
 */
class EntityHelperTest extends TestCase {

  /**
   * The EntityHelper under test.
   */
  protected EntityHelper $entityHelper;

  /**
   * Mock services.
   */
  protected EntityTypeManagerInterface $entityTypeManager;
  protected RouteMatchInterface $routeMatch;
  protected LanguageManagerInterface $languageManager;
  protected EntityRepositoryInterface $entityRepository;
  protected ConfigFactoryInterface $configFactory;
  protected Connection $connection;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->routeMatch = $this->createMock(RouteMatchInterface::class);
    $this->languageManager = $this->createMock(LanguageManagerInterface::class);
    $this->entityRepository = $this->createMock(EntityRepositoryInterface::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->connection = $this->createMock(Connection::class);

    $renderer = $this->createMock(RendererInterface::class);
    $renderer->method('renderInIsolation')
      ->willReturnCallback(function ($render) {
        return '<p>' . ($render['#text'] ?? '') . '</p>';
      });

    $this->entityHelper = new EntityHelper(
      $this->entityTypeManager,
      $this->routeMatch,
      $this->languageManager,
      $this->entityRepository,
      $this->configFactory,
      $this->connection,
      $this->createMock(CacheBackendInterface::class),
      $this->createMock(MenuLinkTreeInterface::class),
      $this->createMock(FileUrlGeneratorInterface::class),
      $this->createMock(LoggerChannelFactoryInterface::class),
      $renderer,
      $this->createMock(DateFormatterInterface::class),
      $this->createMock(ImageFactory::class),
      $this->createMock(RequestStack::class),
      $this->createMock(MenuActiveTrailResolver::class),
      $this->createMock(TaxonomyTreeBuilder::class),
      $this->createMock(MenuTreeBuilder::class),
      $this->createMock(MediaArrayBuilder::class),
    );

    // Set up container (still needed for optional breadcrumb service).
    $container = new ContainerBuilder();
    // Cache::mergeContexts() and CacheableMetadata::addCacheContexts()
    // call cache_contexts_manager->assertValidTokens() under the hood.
    // Without this stub any test that bubbles cache contexts via
    // CacheableMetadata::createFromRenderArray() would fail with
    // ServiceNotFoundException.
    $cache_contexts_manager = $this->createMock(CacheContextsManager::class);
    $cache_contexts_manager->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $cache_contexts_manager);
    \Drupal::setContainer($container);
  }

  /**
   * Helper: create a mock entity with configurable hasField() and get().
   */
  protected function createMockEntity(string $entityClass, array $fields = []) {
    $entity = $this->createMock($entityClass);

    $entity->method('hasField')
      ->willReturnCallback(function ($field_name) use ($fields) {
        return array_key_exists($field_name, $fields);
      });

    $entity->method('get')
      ->willReturnCallback(function ($field_name) use ($fields) {
        if (!isset($fields[$field_name])) {
          return NULL;
        }
        return new StubFieldItemList($fields[$field_name]);
      });

    return $entity;
  }

  /**
   * Build a fresh EntityHelper, optionally overriding individual services.
   *
   * Used by tests that need to inject a custom renderer or menu link tree
   * to exercise cache metadata bubbling paths. Unspecified services fall
   * back to the setUp mocks or fresh generic mocks.
   *
   * @param array $overrides
   *   Keyed by constructor arg short name (e.g. 'renderer', 'menu_link_tree').
   */
  protected function createHelperWithOverrides(array $overrides = []): EntityHelper {
    return new EntityHelper(
      $overrides['entity_type_manager'] ?? $this->entityTypeManager,
      $overrides['route_match'] ?? $this->routeMatch,
      $overrides['language_manager'] ?? $this->languageManager,
      $overrides['entity_repository'] ?? $this->entityRepository,
      $overrides['config_factory'] ?? $this->configFactory,
      $overrides['connection'] ?? $this->connection,
      $overrides['cache_backend'] ?? $this->createMock(CacheBackendInterface::class),
      $overrides['menu_link_tree'] ?? $this->createMock(MenuLinkTreeInterface::class),
      $overrides['file_url_generator'] ?? $this->createMock(FileUrlGeneratorInterface::class),
      $overrides['logger_factory'] ?? $this->createMock(LoggerChannelFactoryInterface::class),
      $overrides['renderer'] ?? $this->createMock(RendererInterface::class),
      $overrides['date_formatter'] ?? $this->createMock(DateFormatterInterface::class),
      $overrides['image_factory'] ?? $this->createMock(ImageFactory::class),
      $overrides['request_stack'] ?? $this->createMock(RequestStack::class),
      $overrides['menu_active_trail_resolver'] ?? $this->createMock(MenuActiveTrailResolver::class),
      $overrides['taxonomy_tree_builder'] ?? $this->createMock(TaxonomyTreeBuilder::class),
      $overrides['menu_tree_builder'] ?? $this->createMock(MenuTreeBuilder::class),
      $overrides['media_array_builder'] ?? $this->createMock(MediaArrayBuilder::class),
    );
  }

  // ---------------------------------------------------------------
  // getTextField tests
  // ---------------------------------------------------------------

  /**
   * @covers ::getTextField
   */
  public function testGetTextFieldAddsPrefix(): void {
    $entity = $this->createMockEntity(ContentEntityInterface::class, [
      'field_title' => [['value' => 'Hello']],
    ]);
    $this->assertSame('Hello', $this->entityHelper->getTextField($entity, 'title'));
  }

  /**
   * @covers ::getTextField
   */
  public function testGetTextFieldKeepsPrefix(): void {
    $entity = $this->createMockEntity(ContentEntityInterface::class, [
      'field_title' => [['value' => 'Hello']],
    ]);
    $this->assertSame('Hello', $this->entityHelper->getTextField($entity, 'field_title'));
  }

  /**
   * @covers ::getTextField
   */
  public function testGetTextFieldReturnsFalseWhenMissing(): void {
    $entity = $this->createMockEntity(ContentEntityInterface::class, []);
    $this->assertFalse($this->entityHelper->getTextField($entity, 'title'));
  }

  /**
   * @covers ::getTextField
   */
  public function testGetTextFieldReturnsEmptyWhenEmpty(): void {
    $entity = $this->createMockEntity(ContentEntityInterface::class, [
      'field_title' => [],
    ]);
    $this->assertSame('', $this->entityHelper->getTextField($entity, 'title'));
  }

  /**
   * @covers ::getTextField
   */
  public function testGetTextFieldReturnsSingleValue(): void {
    $entity = $this->createMockEntity(ContentEntityInterface::class, [
      'field_title' => [['value' => 'Single']],
    ]);
    $this->assertSame('Single', $this->entityHelper->getTextField($entity, 'title'));
  }

  /**
   * @covers ::getTextField
   */
  public function testGetTextFieldReturnsArrayWhenMultiple(): void {
    $entity = $this->createMockEntity(ContentEntityInterface::class, [
      'field_tags' => [
        ['value' => 'a'],
        ['value' => 'b'],
        ['value' => 'c'],
      ],
    ]);
    $result = $this->entityHelper->getTextField($entity, 'tags');
    $this->assertIsArray($result);
    $this->assertCount(3, $result);
    $this->assertSame('a', $result[0]);
  }

  /**
   * @covers ::getTextField
   */
  public function testGetTextFieldReturnsArrayWhenForced(): void {
    $entity = $this->createMockEntity(ContentEntityInterface::class, [
      'field_title' => [['value' => 'val']],
    ]);
    $result = $this->entityHelper->getTextField($entity, 'title', ['return_format' => 'array']);
    $this->assertIsArray($result);
    $this->assertSame('val', $result[0]);
  }

  /**
   * @covers ::getTextField
   */
  public function testGetTextFieldNonFieldPrefix(): void {
    $entity = $this->createMockEntity(ContentEntityInterface::class, [
      'node_read_time' => [['value' => '3']],
    ]);
    $this->assertSame('3', $this->entityHelper->getTextField($entity, 'node_read_time'));
  }

  // ---------------------------------------------------------------
  // getTextareaField tests
  // ---------------------------------------------------------------

  /**
   * @covers ::getTextareaField
   */
  public function testTextareaBodyNoPrefix(): void {
    $entity = $this->createMockEntity(NodeInterface::class, [
      'body' => [['value' => 'Body text', 'format' => 'full_html']],
    ]);
    $this->assertStringContainsString('Body text', (string) $this->entityHelper->getTextareaField($entity, 'body'));
  }

  /**
   * @covers ::getTextareaField
   */
  public function testTextareaDescriptionNoPrefix(): void {
    $entity = $this->createMockEntity(TermInterface::class, [
      'description' => [['value' => 'Desc text', 'format' => 'full_html']],
    ]);
    $this->assertStringContainsString('Desc text', (string) $this->entityHelper->getTextareaField($entity, 'description'));
  }

  /**
   * @covers ::getTextareaField
   */
  public function testTextareaCommentBodyNoPrefix(): void {
    $entity = $this->createMockEntity(CommentInterface::class, [
      'comment_body' => [['value' => 'Comment', 'format' => 'full_html']],
    ]);
    $this->assertStringContainsString('Comment', (string) $this->entityHelper->getTextareaField($entity, 'comment_body'));
  }

  /**
   * @covers ::getTextareaField
   */
  public function testTextareaRegularAddsPrefix(): void {
    $entity = $this->createMockEntity(ContentEntityInterface::class, [
      'field_summary' => [['value' => 'Summary text', 'format' => 'full_html']],
    ]);
    $this->assertStringContainsString('Summary text', (string) $this->entityHelper->getTextareaField($entity, 'summary'));
  }

  // ---------------------------------------------------------------
  // getOfficeHoursField optional-integration tests
  // ---------------------------------------------------------------

  /**
   * Guards against regression of the early-return guard for the optional
   * office_hours module. When OfficeHoursDateHelper is not available
   * (module not installed), the method must return FALSE without touching
   * the missing class — not crash with a fatal.
   *
   * The host project for this test suite does not have drupal/office_hours
   * installed, so this exercises the guard branch directly.
   *
   * To specifically cover the guard (rather than falling through to the
   * later `validateField` check), the mocked entity carries an
   * `opening_hours` field. Without the guard, the method would proceed to
   * iterate field items and call OfficeHoursDateHelper::format() on a
   * missing class — a fatal in production. With the guard, it bails out
   * before ever touching the class.
   *
   * @covers ::getOfficeHoursField
   */
  public function testOfficeHoursReturnsFalseWhenModuleMissing(): void {
    $this->assertFalse(
      class_exists(\Drupal\office_hours\OfficeHoursDateHelper::class),
      'Test environment must NOT have office_hours installed; otherwise this test covers nothing.'
    );

    $entity = $this->createMockEntity(ContentEntityInterface::class, [
      'opening_hours' => [
        ['day' => 1, 'starthours' => '0900', 'endhours' => '1700', 'comment' => ''],
      ],
    ]);
    $this->assertFalse($this->entityHelper->getOfficeHoursField($entity, 'opening_hours'));
  }

  // ---------------------------------------------------------------
  // getEntityReferenceField prefix tests
  // ---------------------------------------------------------------

  /**
   * @covers ::getEntityReferenceField
   */
  public function testEntityRefRegularAddsPrefix(): void {
    $entity = $this->createMockEntity(ContentEntityInterface::class, []);
    $this->assertFalse($this->entityHelper->getEntityReferenceField($entity, 'items'));
  }

  // ---------------------------------------------------------------
  // getBooleanField tests
  // ---------------------------------------------------------------

  /**
   * @covers ::getBooleanField
   */
  public function testBooleanFieldTrue(): void {
    $entity = $this->createMockEntity(ContentEntityInterface::class, [
      'field_active' => [['value' => 1]],
    ]);
    $this->assertTrue($this->entityHelper->getBooleanField($entity, 'active'));
  }

  /**
   * @covers ::getBooleanField
   */
  public function testBooleanFieldFalse(): void {
    $entity = $this->createMockEntity(ContentEntityInterface::class, [
      'field_active' => [['value' => 0]],
    ]);
    $this->assertFalse($this->entityHelper->getBooleanField($entity, 'active'));
  }

  /**
   * @covers ::getBooleanField
   */
  public function testBooleanFieldEmpty(): void {
    $entity = $this->createMockEntity(ContentEntityInterface::class, [
      'field_active' => [],
    ]);
    $this->assertFalse($this->entityHelper->getBooleanField($entity, 'active'));
  }

  /**
   * @covers ::getBooleanField
   */
  public function testBooleanFieldMissing(): void {
    $entity = $this->createMockEntity(ContentEntityInterface::class, []);
    $this->assertFalse($this->entityHelper->getBooleanField($entity, 'active'));
  }

  // ---------------------------------------------------------------
  // getSelectField tests
  // ---------------------------------------------------------------

  /**
   * @covers ::getSelectField
   */
  public function testSelectFieldSingle(): void {
    $entity = $this->createMockEntity(ContentEntityInterface::class, [
      'field_color' => [['value' => 'red']],
    ]);
    $this->assertSame('red', $this->entityHelper->getSelectField($entity, 'color'));
  }

  /**
   * @covers ::getSelectField
   */
  public function testSelectFieldMultiple(): void {
    $entity = $this->createMockEntity(ContentEntityInterface::class, [
      'field_color' => [['value' => 'red'], ['value' => 'blue']],
    ]);
    $result = $this->entityHelper->getSelectField($entity, 'color');
    $this->assertIsArray($result);
    $this->assertCount(2, $result);
  }

  /**
   * @covers ::getSelectField
   */
  public function testSelectFieldEmpty(): void {
    $entity = $this->createMockEntity(ContentEntityInterface::class, [
      'field_color' => [],
    ]);
    $this->assertSame('', $this->entityHelper->getSelectField($entity, 'color'));
  }

  // ---------------------------------------------------------------
  // Helper: createMockEntityWithDefinitions
  // ---------------------------------------------------------------

  /**
   * Create a mock entity with field definitions for formatField tests.
   *
   * @param string $entityClass
   *   The entity interface class to mock.
   * @param array $fields
   *   Map of field_name => items array (same as createMockEntity).
   * @param array $field_definitions
   *   Map of field_name => ['type' => ..., 'settings' => [...]].
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked entity.
   */
  protected function createMockEntityWithDefinitions(string $entityClass, array $fields, array $field_definitions) {
    $entity = $this->createMockEntity($entityClass, $fields);

    $def_mocks = [];
    foreach ($field_definitions as $name => $info) {
      $def = $this->createMock(FieldDefinitionInterface::class);
      $def->method('getType')->willReturn($info['type']);
      $def->method('getSettings')->willReturn($info['settings'] ?? []);
      $def_mocks[$name] = $def;
    }

    $entity->method('getFieldDefinition')
      ->willReturnCallback(function ($name) use ($def_mocks) {
        return $def_mocks[$name] ?? NULL;
      });

    return $entity;
  }

  // ---------------------------------------------------------------
  // formatField tests
  // ---------------------------------------------------------------

  /**
   * @covers ::formatField
   */
  public function testFormatFieldMissingField(): void {
    $entity = $this->createMockEntity(ContentEntityInterface::class, []);
    $this->assertFalse($this->entityHelper->formatField($entity, 'nonexistent'));
  }

  /**
   * @covers ::formatField
   */
  public function testFormatFieldStringType(): void {
    $entity = $this->createMockEntityWithDefinitions(
      ContentEntityInterface::class,
      ['field_title' => [['value' => 'Hello World']]],
      ['field_title' => ['type' => 'string']],
    );
    $this->assertSame('Hello World', $this->entityHelper->formatField($entity, 'title'));
  }

  /**
   * @covers ::formatField
   */
  public function testFormatFieldTextLongType(): void {
    $entity = $this->createMockEntityWithDefinitions(
      ContentEntityInterface::class,
      ['field_desc' => [['value' => 'Rich text', 'format' => 'full_html']]],
      ['field_desc' => ['type' => 'text_long']],
    );
    $result = $this->entityHelper->formatField($entity, 'desc');
    $this->assertStringContainsString('Rich text', (string) $result);
  }

  /**
   * @covers ::formatField
   */
  public function testFormatFieldBooleanType(): void {
    $entity = $this->createMockEntityWithDefinitions(
      ContentEntityInterface::class,
      ['field_active' => [['value' => 1]]],
      ['field_active' => ['type' => 'boolean']],
    );
    $this->assertTrue($this->entityHelper->formatField($entity, 'active'));
  }

  /**
   * @covers ::formatField
   */
  public function testFormatFieldLinkType(): void {
    $helper = $this->getMockBuilder(EntityHelper::class)
      ->setConstructorArgs([
        $this->entityTypeManager,
        $this->routeMatch,
        $this->languageManager,
        $this->entityRepository,
        $this->configFactory,
        $this->connection,
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
      ])
      ->onlyMethods(['getLinkField'])
      ->getMock();

    $entity = $this->createMockEntityWithDefinitions(
      ContentEntityInterface::class,
      ['field_cta' => [['uri' => 'https://example.com', 'title' => 'Click']]],
      ['field_cta' => ['type' => 'link']],
    );

    $helper->expects($this->once())
      ->method('getLinkField')
      ->with($entity, 'field_cta', [])
      ->willReturn(['url' => 'https://example.com', 'title' => 'Click']);

    $result = $helper->formatField($entity, 'cta');
    $this->assertSame('https://example.com', $result['url']);
  }

  /**
   * @covers ::formatField
   */
  public function testFormatFieldListStringType(): void {
    $entity = $this->createMockEntityWithDefinitions(
      ContentEntityInterface::class,
      ['field_color' => [['value' => 'red']]],
      ['field_color' => ['type' => 'list_string']],
    );
    $this->assertSame('red', $this->entityHelper->formatField($entity, 'color'));
  }

  /**
   * @covers ::formatField
   */
  public function testFormatFieldEntityRefMedia(): void {
    $helper = $this->getMockBuilder(EntityHelper::class)
      ->setConstructorArgs([
        $this->entityTypeManager,
        $this->routeMatch,
        $this->languageManager,
        $this->entityRepository,
        $this->configFactory,
        $this->connection,
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
      ])
      ->onlyMethods(['getMediaField'])
      ->getMock();

    $entity = $this->createMockEntityWithDefinitions(
      ContentEntityInterface::class,
      ['field_image' => [['target_id' => 1]]],
      ['field_image' => ['type' => 'entity_reference', 'settings' => ['handler' => 'default:media']]],
    );

    $helper->expects($this->once())
      ->method('getMediaField')
      ->with($entity, 'field_image', [])
      ->willReturn(['src' => '/image.jpg']);

    $result = $helper->formatField($entity, 'image');
    $this->assertSame('/image.jpg', $result['src']);
  }

  /**
   * @covers ::formatField
   */
  public function testFormatFieldEntityRefTaxonomy(): void {
    $helper = $this->getMockBuilder(EntityHelper::class)
      ->setConstructorArgs([
        $this->entityTypeManager,
        $this->routeMatch,
        $this->languageManager,
        $this->entityRepository,
        $this->configFactory,
        $this->connection,
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
      ])
      ->onlyMethods(['getTermField'])
      ->getMock();

    $entity = $this->createMockEntityWithDefinitions(
      ContentEntityInterface::class,
      ['field_category' => [['target_id' => 5]]],
      ['field_category' => ['type' => 'entity_reference', 'settings' => ['handler' => 'default:taxonomy_term']]],
    );

    $helper->expects($this->once())
      ->method('getTermField')
      ->with($entity, 'field_category', [])
      ->willReturn(['id' => 5, 'title' => 'News']);

    $result = $helper->formatField($entity, 'category');
    $this->assertSame('News', $result['title']);
  }

  /**
   * @covers ::formatField
   */
  public function testFormatFieldEntityRefGeneric(): void {
    $helper = $this->getMockBuilder(EntityHelper::class)
      ->setConstructorArgs([
        $this->entityTypeManager,
        $this->routeMatch,
        $this->languageManager,
        $this->entityRepository,
        $this->configFactory,
        $this->connection,
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
      ])
      ->onlyMethods(['getEntityReferenceField'])
      ->getMock();

    $entity = $this->createMockEntityWithDefinitions(
      ContentEntityInterface::class,
      ['field_related' => [['target_id' => 10]]],
      ['field_related' => ['type' => 'entity_reference', 'settings' => ['handler' => 'default:node']]],
    );

    $helper->expects($this->once())
      ->method('getEntityReferenceField')
      ->with($entity, 'field_related', [])
      ->willReturn($this->createMock(ContentEntityInterface::class));

    $result = $helper->formatField($entity, 'related');
    $this->assertInstanceOf(ContentEntityInterface::class, $result);
  }

  /**
   * @covers ::formatField
   */
  public function testFormatFieldEntityRefRevisions(): void {
    $helper = $this->getMockBuilder(EntityHelper::class)
      ->setConstructorArgs([
        $this->entityTypeManager,
        $this->routeMatch,
        $this->languageManager,
        $this->entityRepository,
        $this->configFactory,
        $this->connection,
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
      ])
      ->onlyMethods(['getEntityReferenceField'])
      ->getMock();

    $entity = $this->createMockEntityWithDefinitions(
      ContentEntityInterface::class,
      ['field_paragraphs' => [['target_id' => 7]]],
      ['field_paragraphs' => ['type' => 'entity_reference_revisions', 'settings' => ['handler' => 'default:paragraph']]],
    );

    $helper->expects($this->once())
      ->method('getEntityReferenceField')
      ->with($entity, 'field_paragraphs', [])
      ->willReturn([]);

    $helper->formatField($entity, 'paragraphs');
  }

  /**
   * @covers ::formatField
   */
  public function testFormatFieldForwardsParams(): void {
    $entity = $this->createMockEntityWithDefinitions(
      ContentEntityInterface::class,
      ['field_tags' => [['value' => 'a'], ['value' => 'b']]],
      ['field_tags' => ['type' => 'string']],
    );
    $result = $this->entityHelper->formatField($entity, 'tags', ['return_format' => 'array']);
    $this->assertIsArray($result);
    $this->assertCount(2, $result);
  }

  /**
   * @covers ::formatField
   */
  public function testFormatFieldNonFieldPrefix(): void {
    $entity = $this->createMockEntityWithDefinitions(
      ContentEntityInterface::class,
      ['node_read_time' => [['value' => '5']]],
      ['node_read_time' => ['type' => 'integer']],
    );
    $this->assertSame('5', $this->entityHelper->formatField($entity, 'node_read_time'));
  }

  /**
   * @covers ::formatField
   */
  public function testFormatFieldPrefixedName(): void {
    $entity = $this->createMockEntityWithDefinitions(
      ContentEntityInterface::class,
      ['field_title' => [['value' => 'Prefixed']]],
      ['field_title' => ['type' => 'string']],
    );
    $this->assertSame('Prefixed', $this->entityHelper->formatField($entity, 'field_title'));
  }

  /**
   * @covers ::formatField
   */
  public function testFormatFieldWebformType(): void {
    $helper = $this->getMockBuilder(EntityHelper::class)
      ->setConstructorArgs([
        $this->entityTypeManager,
        $this->routeMatch,
        $this->languageManager,
        $this->entityRepository,
        $this->configFactory,
        $this->connection,
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
      ])
      ->onlyMethods(['getWebformField'])
      ->getMock();

    $entity = $this->createMockEntityWithDefinitions(
      ContentEntityInterface::class,
      ['field_webform' => [['target_id' => 'contact']]],
      ['field_webform' => ['type' => 'webform']],
    );

    $helper->expects($this->once())
      ->method('getWebformField')
      ->with($entity, 'field_webform')
      ->willReturn(['#type' => 'form', '#form_id' => 'contact']);

    $result = $helper->formatField($entity, 'webform');
    $this->assertSame('form', $result['#type']);
  }

  // ---------------------------------------------------------------
  // Helper: createMockEntityWithAllDefinitions
  // ---------------------------------------------------------------

  /**
   * Create a mock entity with both singular and plural field definition mocks.
   *
   * Extends createMockEntityWithDefinitions by also mocking
   * getFieldDefinitions() to return the full definition map.
   *
   * @param string $entityClass
   *   The entity interface class to mock.
   * @param array $fields
   *   Map of field_name => items array.
   * @param array $field_definitions
   *   Map of field_name => ['type' => ..., 'settings' => [...]].
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked entity.
   */
  protected function createMockEntityWithAllDefinitions(string $entityClass, array $fields, array $field_definitions) {
    $entity = $this->createMockEntityWithDefinitions($entityClass, $fields, $field_definitions);

    $def_mocks = [];
    foreach ($field_definitions as $name => $info) {
      $def = $this->createMock(FieldDefinitionInterface::class);
      $def->method('getType')->willReturn($info['type']);
      $def->method('getSettings')->willReturn($info['settings'] ?? []);
      $def_mocks[$name] = $def;
    }

    $entity->method('getFieldDefinitions')
      ->willReturn($def_mocks);

    return $entity;
  }

  // ---------------------------------------------------------------
  // formatFields tests
  // ---------------------------------------------------------------

  /**
   * @covers ::formatFields
   */
  public function testFormatFieldsEmpty(): void {
    $entity = $this->createMockEntityWithAllDefinitions(
      ContentEntityInterface::class,
      [],
      [],
    );
    $this->assertSame([], $this->entityHelper->formatFields($entity));
  }

  /**
   * @covers ::formatFields
   */
  public function testFormatFieldsMixed(): void {
    $entity = $this->createMockEntityWithAllDefinitions(
      ContentEntityInterface::class,
      [
        'field_title' => [['value' => 'Hello']],
        'field_active' => [['value' => 1]],
      ],
      [
        'field_title' => ['type' => 'string'],
        'field_active' => ['type' => 'boolean'],
      ],
    );
    $result = $this->entityHelper->formatFields($entity);
    $this->assertSame('Hello', $result['title']);
    $this->assertTrue($result['active']);
  }

  /**
   * @covers ::formatFields
   */
  public function testFormatFieldsSkipsEmpty(): void {
    $entity = $this->createMockEntityWithAllDefinitions(
      ContentEntityInterface::class,
      [
        'field_title' => [['value' => 'Hello']],
        'field_desc' => [],
      ],
      [
        'field_title' => ['type' => 'string'],
        'field_desc' => ['type' => 'string'],
      ],
    );
    $result = $this->entityHelper->formatFields($entity);
    $this->assertArrayHasKey('title', $result);
    $this->assertArrayNotHasKey('desc', $result);
  }

  /**
   * @covers ::formatFields
   */
  public function testFormatFieldsIncludesBaseFields(): void {
    $entity = $this->createMockEntityWithAllDefinitions(
      ContentEntityInterface::class,
      [
        'nid' => [['value' => '42']],
        'status' => [['value' => '1']],
        'field_title' => [['value' => 'Hello']],
      ],
      [
        'nid' => ['type' => 'integer'],
        'status' => ['type' => 'boolean'],
        'field_title' => ['type' => 'string'],
      ],
    );
    $result = $this->entityHelper->formatFields($entity);
    $this->assertSame('42', $result['nid']);
    $this->assertSame('1', $result['status']);
    $this->assertSame('Hello', $result['title']);
  }

  /**
   * @covers ::formatFields
   */
  public function testFormatFieldsForwardsParams(): void {
    $entity = $this->createMockEntityWithAllDefinitions(
      ContentEntityInterface::class,
      [
        'field_tags' => [['value' => 'a'], ['value' => 'b']],
      ],
      [
        'field_tags' => ['type' => 'string'],
      ],
    );
    $result = $this->entityHelper->formatFields($entity, ['return_format' => 'array']);
    $this->assertIsArray($result['tags']);
    $this->assertCount(2, $result['tags']);
  }

  // ---------------------------------------------------------------
  // Helper: createPartialHelper
  // ---------------------------------------------------------------

  /**
   * Create a partial mock of EntityHelper with specific methods mocked.
   *
   * @param array $methods
   *   Methods to mock.
   *
   * @return \Drupal\custom_components\Services\EntityHelper|\PHPUnit\Framework\MockObject\MockObject
   *   The partial mock.
   */
  protected function createPartialHelper(array $methods) {
    return $this->getMockBuilder(EntityHelper::class)
      ->setConstructorArgs([
        $this->entityTypeManager,
        $this->routeMatch,
        $this->languageManager,
        $this->entityRepository,
        $this->configFactory,
        $this->connection,
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
      ])
      ->onlyMethods($methods)
      ->getMock();
  }

  /**
   * Helper: create a mock entity with field definitions including cardinality.
   *
   * @param string $entityClass
   *   The entity interface class to mock.
   * @param array $fields
   *   Map of field_name => items array.
   * @param array $field_definitions
   *   Map of field_name => ['type' => ..., 'cardinality' => ...].
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked entity.
   */
  protected function createMockEntityWithCardinality(string $entityClass, array $fields, array $field_definitions) {
    $entity = $this->createMockEntity($entityClass, $fields);

    $def_mocks = [];
    foreach ($field_definitions as $name => $info) {
      if (array_key_exists('instance_cardinality', $info)) {
        $def = $this->createMock(FieldConfigInterface::class);
        $def->method('getThirdPartySetting')
          ->with('field_config_cardinality', 'cardinality_config')
          ->willReturn($info['instance_cardinality']);
      }
      else {
        $def = $this->createMock(FieldDefinitionInterface::class);
      }
      $def->method('getType')->willReturn($info['type']);
      $def->method('getSettings')->willReturn($info['settings'] ?? []);

      $storage_def = $this->createMock(FieldStorageDefinitionInterface::class);
      $storage_def->method('getCardinality')->willReturn($info['cardinality'] ?? 1);
      $def->method('getFieldStorageDefinition')->willReturn($storage_def);

      $def_mocks[$name] = $def;
    }

    $entity->method('getFieldDefinition')
      ->willReturnCallback(function ($name) use ($def_mocks) {
        return $def_mocks[$name] ?? NULL;
      });

    $entity->method('getFieldDefinitions')
      ->willReturn($def_mocks);

    return $entity;
  }

  // ---------------------------------------------------------------
  // mapFields tests
  // ---------------------------------------------------------------

  /**
   * @covers ::mapFields
   */
  public function testMapFieldsEmptyMapDelegatesToFormatFields(): void {
    $entity = $this->createMockEntityWithAllDefinitions(
      ContentEntityInterface::class,
      [
        'field_title' => [['value' => 'Hello']],
        'field_active' => [['value' => 1]],
      ],
      [
        'field_title' => ['type' => 'string'],
        'field_active' => ['type' => 'boolean'],
      ],
    );
    $result = $this->entityHelper->mapFields($entity);
    $this->assertSame('Hello', $result['title']);
    $this->assertTrue($result['active']);
  }

  /**
   * @covers ::mapFields
   */
  public function testMapFieldsFlatStringMapping(): void {
    $entity = $this->createMockEntityWithCardinality(
      ContentEntityInterface::class,
      [
        'field_title' => [['value' => 'Hello']],
        'field_name' => [['value' => 'John']],
      ],
      [
        'field_title' => ['type' => 'string', 'cardinality' => 1],
        'field_name' => ['type' => 'string', 'cardinality' => 1],
      ],
    );
    $result = $this->entityHelper->mapFields($entity, [
      'heading' => 'title',
      'author' => 'name',
    ]);
    $this->assertSame('Hello', $result['heading']);
    $this->assertSame('John', $result['author']);
  }

  /**
   * @covers ::mapFields
   */
  public function testMapFieldsAutoArrayForMultiCardinality(): void {
    $entity = $this->createMockEntityWithCardinality(
      ContentEntityInterface::class,
      [
        'field_tags' => [['value' => 'a'], ['value' => 'b']],
      ],
      [
        'field_tags' => ['type' => 'string', 'cardinality' => -1],
      ],
    );
    $result = $this->entityHelper->mapFields($entity, [
      'tags' => 'tags',
    ]);
    $this->assertIsArray($result['tags']);
    $this->assertCount(2, $result['tags']);
  }

  /**
   * @covers ::mapFields
   */
  public function testMapFieldsNestedAutoFormat(): void {
    // Use partial mock to control getEntityReferenceField and formatFields.
    $helper = $this->createPartialHelper(['getEntityReferenceField', 'formatFields']);

    $child1 = $this->createMock(ContentEntityInterface::class);
    $child2 = $this->createMock(ContentEntityInterface::class);

    $entity = $this->createMockEntityWithCardinality(
      ContentEntityInterface::class,
      ['field_paragraphs' => [['target_id' => 1], ['target_id' => 2]]],
      ['field_paragraphs' => ['type' => 'entity_reference_revisions', 'cardinality' => -1]],
    );

    $helper->expects($this->once())
      ->method('getEntityReferenceField')
      ->with($entity, 'paragraphs', ['return_format' => 'array'])
      ->willReturn([$child1, $child2]);

    $helper->method('formatFields')
      ->willReturnOnConsecutiveCalls(
        ['title' => 'Item 1', 'value' => '10'],
        ['title' => 'Item 2', 'value' => '20'],
      );

    $result = $helper->mapFields($entity, [
      'items' => 'paragraphs',
    ]);

    $this->assertCount(2, $result['items']);
    $this->assertSame('Item 1', $result['items'][0]['title']);
    $this->assertSame('Item 2', $result['items'][1]['title']);
  }

  /**
   * @covers ::mapFields
   */
  public function testMapFieldsDotNotation(): void {
    $helper = $this->createPartialHelper(['getEntityReferenceField', 'formatField']);

    $child1 = $this->createMock(ContentEntityInterface::class);
    $child1->method('hasField')->willReturn(FALSE);

    $entity = $this->createMockEntityWithCardinality(
      ContentEntityInterface::class,
      ['field_paragraphs' => [['target_id' => 1]]],
      ['field_paragraphs' => ['type' => 'entity_reference_revisions', 'cardinality' => -1]],
    );

    $helper->expects($this->once())
      ->method('getEntityReferenceField')
      ->with($entity, 'paragraphs', ['return_format' => 'array'])
      ->willReturn([$child1]);

    $helper->method('formatField')
      ->willReturnMap([
        [$child1, 'title', [], 'FAQ Question'],
        [$child1, 'text', [], '<p>FAQ Answer</p>'],
      ]);

    $result = $helper->mapFields($entity, [
      'items' => [
        'question' => 'paragraphs.title',
        'answer' => 'paragraphs.text',
      ],
    ]);

    $this->assertCount(1, $result['items']);
    $this->assertSame('FAQ Question', $result['items'][0]['question']);
    $this->assertSame('<p>FAQ Answer</p>', $result['items'][0]['answer']);
  }

  /**
   * @covers ::mapFields
   */
  public function testMapFieldsExplicitMethodOverride(): void {
    $helper = $this->createPartialHelper(['getDoubleField']);

    $entity = $this->createMock(ContentEntityInterface::class);

    $helper->expects($this->once())
      ->method('getDoubleField')
      ->with($entity, 'data', ['key1' => 'label'])
      ->willReturn(['key1' => 'Value']);

    $result = $helper->mapFields($entity, [
      'data' => ['method' => 'getDoubleField', 'field' => 'data', 'params' => [['key1' => 'label']]],
    ]);

    $this->assertSame(['key1' => 'Value'], $result['data']);
  }

  /**
   * @covers ::mapFields
   */
  public function testMapFieldsMissingFieldReturnsLiteral(): void {
    $entity = $this->createMockEntityWithCardinality(
      ContentEntityInterface::class,
      [],
      [],
    );
    // String that doesn't match any field is returned as literal value.
    $result = $this->entityHelper->mapFields($entity, [
      'title' => 'title',
    ]);
    $this->assertSame('title', $result['title']);
  }

  /**
   * @covers ::mapFields
   */
  public function testMapFieldsMixedConfigs(): void {
    $helper = $this->createPartialHelper(['getEntityReferenceField', 'formatField', 'formatFields']);

    $child = $this->createMock(ContentEntityInterface::class);

    $entity = $this->createMockEntityWithCardinality(
      ContentEntityInterface::class,
      [
        'field_title' => [['value' => 'Hello']],
        'field_paragraphs' => [['target_id' => 1]],
      ],
      [
        'field_title' => ['type' => 'string', 'cardinality' => 1],
        'field_paragraphs' => ['type' => 'entity_reference_revisions', 'cardinality' => -1],
      ],
    );

    $helper->method('formatField')
      ->willReturn('Hello');

    $helper->method('getEntityReferenceField')
      ->willReturn([$child]);

    $helper->method('formatFields')
      ->willReturn(['value' => '10']);

    $result = $helper->mapFields($entity, [
      'heading' => 'title',
      'items' => 'paragraphs',
    ]);

    $this->assertSame('Hello', $result['heading']);
    $this->assertCount(1, $result['items']);
    $this->assertSame('10', $result['items'][0]['value']);
  }

  /**
   * @covers ::mapFields
   */
  public function testMapFieldsArrayConfigAutoCardinality(): void {
    $helper = $this->createPartialHelper(['formatField']);

    $entity = $this->createMockEntityWithCardinality(
      ContentEntityInterface::class,
      ['field_media' => [['target_id' => 1]]],
      [
        'field_media' => [
          'type' => 'entity_reference',
          'cardinality' => -1,
          'settings' => ['handler' => 'default:media'],
        ],
      ],
    );

    // Auto-cardinality adds return_format, extra key is merged on top.
    $helper->expects($this->once())
      ->method('formatField')
      ->with($entity, 'media', ['return_format' => 'array', 'output_type' => 'url'])
      ->willReturn(['https://example.com/img.jpg']);

    $result = $helper->mapFields($entity, [
      'images' => ['field' => 'media', 'output_type' => 'url'],
    ]);

    $this->assertSame(['https://example.com/img.jpg'], $result['images']);
  }

  /**
   * @covers ::mapFields
   */
  public function testMapFieldsShorthandParams(): void {
    $helper = $this->createPartialHelper(['formatField']);

    $entity = $this->createMockEntityWithCardinality(
      ContentEntityInterface::class,
      ['field_link2' => [['uri' => 'https://example.com']]],
      ['field_link2' => ['type' => 'link', 'cardinality' => 1]],
    );

    $helper->expects($this->once())
      ->method('formatField')
      ->with($entity, 'link2', ['output_type' => 'url'])
      ->willReturn('https://example.com');

    $result = $helper->mapFields($entity, [
      'url' => ['field' => 'link2', 'output_type' => 'url'],
    ]);

    $this->assertSame('https://example.com', $result['url']);
  }

  // ---------------------------------------------------------------
  // collectCacheMetadata tests
  // ---------------------------------------------------------------

  /**
   * @covers ::mapFields
   */
  public function testMapFieldsSubObjectMap(): void {
    $entity = $this->createMockEntityWithCardinality(
      ContentEntityInterface::class,
      [
        'field_title' => [['value' => 'Config Title']],
        'field_link' => [['value' => 'link-data']],
        'field_title2' => [['value' => 'Guide Title']],
        'field_link2' => [['value' => 'link2-data']],
      ],
      [
        'field_title' => ['type' => 'string', 'cardinality' => 1],
        'field_link' => ['type' => 'string', 'cardinality' => 1],
        'field_title2' => ['type' => 'string', 'cardinality' => 1],
        'field_link2' => ['type' => 'string', 'cardinality' => 1],
      ],
    );

    // 'configurator' and 'color_guide' are NOT field names on the entity.
    // Each value is a field name to extract.
    $result = $this->entityHelper->mapFields($entity, [
      'configurator' => [
        'title' => 'title',
        'button' => 'link',
      ],
      'color_guide' => [
        'title' => 'title2',
        'button' => 'link2',
      ],
    ]);

    $this->assertIsArray($result['configurator']);
    $this->assertSame('Config Title', $result['configurator']['title']);
    $this->assertSame('link-data', $result['configurator']['button']);
    $this->assertIsArray($result['color_guide']);
    $this->assertSame('Guide Title', $result['color_guide']['title']);
    $this->assertSame('link2-data', $result['color_guide']['button']);
  }

  /**
   * @covers ::mapFields
   */
  public function testMapFieldsSubObjectWithDotNotation(): void {
    // Mixed: sub-object map for heading + dot notation for items.
    $helper = $this->createPartialHelper(['getEntityReferenceField', 'formatField']);

    $child = $this->createMock(ContentEntityInterface::class);
    $child->method('hasField')->willReturn(FALSE);

    $entity = $this->createMockEntityWithCardinality(
      ContentEntityInterface::class,
      [
        'field_title' => [['value' => 'Heading']],
        'field_text' => [['value' => 'Perex', 'format' => 'full_html']],
        'field_paragraphs' => [['target_id' => 1]],
      ],
      [
        'field_title' => ['type' => 'string', 'cardinality' => 1],
        'field_text' => ['type' => 'text_long', 'cardinality' => 1],
        'field_paragraphs' => ['type' => 'entity_reference_revisions', 'cardinality' => -1],
      ],
    );

    $helper->method('getEntityReferenceField')
      ->willReturn([$child]);

    $call_count = 0;
    $helper->method('formatField')
      ->willReturnCallback(function ($ent, $field) use ($entity, $child, &$call_count) {
        // Entity-level fields (sub-object map for heading).
        if ($ent === $entity && $field === 'title') {
          return 'Heading';
        }
        if ($ent === $entity && $field === 'text') {
          return '<p>Perex</p>';
        }
        // Child-level fields (dot notation for items).
        if ($ent === $child && $field === 'media') {
          return ['src' => '/img.jpg'];
        }
        if ($ent === $child && $field === 'title') {
          return 'Item Title';
        }
        return FALSE;
      });

    $result = $helper->mapFields($entity, [
      'heading' => [
        'title' => 'title',
        'perex' => 'text',
      ],
      'items' => [
        'image' => 'paragraphs.media',
        'title' => 'paragraphs.title',
      ],
    ]);

    // Heading is a sub-object ('heading' is not a field on entity).
    $this->assertSame('Heading', $result['heading']['title']);
    $this->assertSame('<p>Perex</p>', $result['heading']['perex']);
    // Items uses dot notation.
    $this->assertCount(1, $result['items']);
    $this->assertSame('Item Title', $result['items'][0]['title']);
  }

  /**
   * @covers ::mapFields
   */
  public function testMapFieldsDotNotationOutputWrap(): void {
    $helper = $this->createPartialHelper(['getEntityReferenceField', 'formatField']);

    $child = $this->createMockEntityWithCardinality(
      ContentEntityInterface::class,
      [
        'field_title' => [['value' => 'Step 1']],
        'field_media' => [['target_id' => 1], ['target_id' => 2]],
      ],
      [
        'field_title' => ['type' => 'string', 'cardinality' => 1],
        'field_media' => [
          'type' => 'entity_reference',
          'cardinality' => -1,
          'settings' => ['handler' => 'default:media'],
        ],
      ],
    );

    $entity = $this->createMockEntityWithCardinality(
      ContentEntityInterface::class,
      ['field_paragraphs' => [['target_id' => 1]]],
      [
        'field_paragraphs' => [
          'type' => 'entity_reference_revisions',
          'cardinality' => -1,
        ],
      ],
    );

    $helper->method('getEntityReferenceField')
      ->willReturn([$child]);

    $helper->method('formatField')
      ->willReturnCallback(function ($ent, $field, $params) use ($child) {
        if ($ent === $child && $field === 'title') {
          return 'Step 1';
        }
        if ($ent === $child && $field === 'media') {
          return ['/img1.jpg', '/img2.jpg'];
        }
        return FALSE;
      });

    $result = $helper->mapFields($entity, [
      'items' => [
        'title' => 'paragraphs.title',
        'images.image' => 'paragraphs.media',
      ],
    ]);

    $this->assertCount(1, $result['items']);
    $this->assertSame('Step 1', $result['items'][0]['title']);
    // Left-side dot wraps each array element.
    $this->assertSame(
      [['image' => '/img1.jpg'], ['image' => '/img2.jpg']],
      $result['items'][0]['images'],
    );
  }

  /**
   * @covers ::mapFields
   */
  public function testMapFieldsPassthroughValues(): void {
    $entity = $this->createMockEntityWithCardinality(
      ContentEntityInterface::class,
      [
        'field_title' => [['value' => 'Hello']],
      ],
      [
        'field_title' => ['type' => 'string', 'cardinality' => 1],
      ],
    );
    $result = $this->entityHelper->mapFields($entity, [
      'title' => 'title',
      'count' => 42,
      'flag' => TRUE,
    ]);
    $this->assertSame('Hello', $result['title']);
    $this->assertSame(42, $result['count']);
    $this->assertTrue($result['flag']);
  }

  /**
   * @covers ::mapFields
   */
  public function testMapFieldsClosureValues(): void {
    $entity = $this->createMockEntityWithCardinality(
      ContentEntityInterface::class,
      [
        'field_title' => [['value' => 'Hello']],
      ],
      [
        'field_title' => ['type' => 'string', 'cardinality' => 1],
      ],
    );
    $result = $this->entityHelper->mapFields($entity, [
      'title' => 'title',
      'computed' => fn($e) => 'value-' . $e->get('field_title')->getString(),
    ]);
    $this->assertSame('Hello', $result['title']);
    $this->assertSame('value-Hello', $result['computed']);
  }

  /**
   * @covers ::mapFields
   */
  public function testMapFieldsDotNotationWithClosure(): void {
    $helper = $this->createPartialHelper(['getEntityReferenceField', 'formatField']);

    $child1 = $this->createMock(ContentEntityInterface::class);
    $child1->method('hasField')->willReturn(FALSE);
    $child1->method('bundle')->willReturn('test_bundle');

    $entity = $this->createMockEntityWithCardinality(
      ContentEntityInterface::class,
      ['field_paragraphs' => [['target_id' => 1]]],
      ['field_paragraphs' => ['type' => 'entity_reference_revisions', 'cardinality' => -1]],
    );

    $helper->expects($this->once())
      ->method('getEntityReferenceField')
      ->with($entity, 'paragraphs', ['return_format' => 'array'])
      ->willReturn([$child1]);

    $helper->method('formatField')
      ->willReturnMap([
        [$child1, 'title', [], 'Item Title'],
      ]);

    $result = $helper->mapFields($entity, [
      'items' => [
        'title' => 'paragraphs.title',
        'custom' => fn($child) => 'processed-' . $child->bundle(),
      ],
    ]);

    $this->assertCount(1, $result['items']);
    $this->assertSame('Item Title', $result['items'][0]['title']);
    $this->assertSame('processed-test_bundle', $result['items'][0]['custom']);
  }

  /**
   * Sub-object value matching field-name pattern but not on entity returns literal.
   *
   * @covers ::mapFields
   */
  public function testMapFieldsSubObjectUnresolvedFieldReturnsLiteral(): void {
    $entity = $this->createMockEntityWithCardinality(
      ContentEntityInterface::class,
      [
        'field_title' => [['value' => 'Hello']],
      ],
      [
        'field_title' => ['type' => 'string', 'cardinality' => 1],
      ],
    );

    // 'nonexistent' looks like a valid field name but doesn't exist on the entity.
    $result = $this->entityHelper->mapFields($entity, [
      'box' => [
        'heading' => 'title',
        'missing' => 'nonexistent',
      ],
    ]);

    $this->assertSame('Hello', $result['box']['heading']);
    // Must return the literal string, not FALSE.
    $this->assertSame('nonexistent', $result['box']['missing']);
  }

  /**
   * Dot-notation config with a non-dot string returns static value per child.
   *
   * @covers ::mapFields
   */
  public function testMapFieldsDotNotationStaticValues(): void {
    $helper = $this->createPartialHelper(['getEntityReferenceField', 'formatField']);

    $child1 = $this->createMock(ContentEntityInterface::class);
    $child1->method('hasField')->willReturn(FALSE);
    $child2 = $this->createMock(ContentEntityInterface::class);
    $child2->method('hasField')->willReturn(FALSE);

    $entity = $this->createMockEntityWithCardinality(
      ContentEntityInterface::class,
      ['field_paragraphs' => [['target_id' => 1], ['target_id' => 2]]],
      ['field_paragraphs' => ['type' => 'entity_reference_revisions', 'cardinality' => -1]],
    );

    $helper->expects($this->once())
      ->method('getEntityReferenceField')
      ->with($entity, 'paragraphs', ['return_format' => 'array'])
      ->willReturn([$child1, $child2]);

    $helper->method('formatField')
      ->willReturnCallback(function ($ent, $field) use ($child1, $child2) {
        if ($ent === $child1 && $field === 'title') {
          return 'Item 1';
        }
        if ($ent === $child2 && $field === 'title') {
          return 'Item 2';
        }
        return FALSE;
      });

    $result = $helper->mapFields($entity, [
      'items' => [
        'title' => 'paragraphs.title',
        'type' => 'card',
      ],
    ]);

    $this->assertCount(2, $result['items']);
    $this->assertSame('Item 1', $result['items'][0]['title']);
    $this->assertSame('card', $result['items'][0]['type']);
    $this->assertSame('Item 2', $result['items'][1]['title']);
    $this->assertSame('card', $result['items'][1]['type']);
  }

  /**
   * @covers ::mapFields
   */
  public function testMapFieldsNestedSubObject(): void {
    $entity = $this->createMockEntityWithCardinality(
      ContentEntityInterface::class,
      [
        'field_title' => [['value' => 'Main']],
        'field_name' => [['value' => 'John']],
        'field_phone' => [['value' => '123']],
      ],
      [
        'field_title' => ['type' => 'string', 'cardinality' => 1],
        'field_name' => ['type' => 'string', 'cardinality' => 1],
        'field_phone' => ['type' => 'string', 'cardinality' => 1],
      ],
    );
    $result = $this->entityHelper->mapFields($entity, [
      'contact' => [
        'title' => 'title',
        'person' => [
          'name' => 'name',
          'phone' => 'phone',
        ],
      ],
    ]);
    $this->assertSame('Main', $result['contact']['title']);
    $this->assertSame('John', $result['contact']['person']['name']);
    $this->assertSame('123', $result['contact']['person']['phone']);
  }

  /**
   * Verify that list arrays (sequential numeric keys) pass through mapFields.
   *
   * Pre-built data like breadcrumb items should not be treated as mapping
   * config. The array_is_list() guard in mapArrayConfig() must return them
   * as-is without attempting field resolution.
   *
   * @covers ::mapFields
   */
  public function testMapFieldsListArrayPassthrough(): void {
    $entity = $this->createMockEntityWithCardinality(
      ContentEntityInterface::class,
      ['field_title' => [['value' => 'Hello']]],
      ['field_title' => ['type' => 'string', 'cardinality' => 1]],
    );

    $breadcrumb = [
      ['url' => '/branches', 'title' => 'Pobočky'],
      ['url' => '/branches/brno', 'title' => 'Brno'],
    ];

    $result = $this->entityHelper->mapFields($entity, [
      'breadcrumb' => $breadcrumb,
      'title' => 'title',
    ]);

    // List array must pass through unchanged.
    $this->assertSame($breadcrumb, $result['breadcrumb']);
    // String field still resolves normally.
    $this->assertSame('Hello', $result['title']);
  }

  /**
   * Non-field-name strings in sub-object maps pass through as literals.
   *
   * @covers ::mapFields
   */
  public function testMapFieldsSubObjectPassthroughNonFieldStrings(): void {
    $entity = $this->createMockEntityWithCardinality(
      ContentEntityInterface::class,
      ['field_title' => [['value' => 'Hello']]],
      ['field_title' => ['type' => 'string', 'cardinality' => 1]],
    );

    $result = $this->entityHelper->mapFields($entity, [
      'links' => [
        'homepage' => 'https://example.com/page.html',
        'api' => 'https://api.example.com/v1/data?key=abc',
        'label' => 'My Title With Spaces',
        'uppercase' => 'ALLCAPS',
      ],
    ]);

    $this->assertSame('https://example.com/page.html', $result['links']['homepage']);
    $this->assertSame('https://api.example.com/v1/data?key=abc', $result['links']['api']);
    $this->assertSame('My Title With Spaces', $result['links']['label']);
    $this->assertSame('ALLCAPS', $result['links']['uppercase']);
  }

  // ---------------------------------------------------------------
  // normalizeReturnValue regression tests (default changed from '' to [])
  // ---------------------------------------------------------------

  /**
   * @covers ::getTextField
   */
  public function testGetTextFieldEmptyReturnsString(): void {
    $entity = $this->createMockEntity(ContentEntityInterface::class, [
      'field_subtitle' => [],
    ]);
    // Scalar helpers must return '' (not []) for empty fields.
    $result = $this->entityHelper->getTextField($entity, 'subtitle');
    $this->assertSame('', $result);
    $this->assertIsString($result);
  }

  /**
   * @covers ::getDateField
   */
  public function testGetDateFieldEmptyReturnsString(): void {
    $entity = $this->createMockEntity(ContentEntityInterface::class, [
      'field_published' => [],
    ]);
    $result = $this->entityHelper->getDateField($entity, 'published');
    $this->assertSame('', $result);
    $this->assertIsString($result);
  }

  /**
   * @covers ::getSelectField
   */
  public function testGetSelectFieldEmptyReturnsString(): void {
    $entity = $this->createMockEntity(ContentEntityInterface::class, [
      'field_status' => [],
    ]);
    $result = $this->entityHelper->getSelectField($entity, 'status');
    $this->assertSame('', $result);
    $this->assertIsString($result);
  }

  /**
   * @covers ::getTextareaField
   */
  public function testGetTextareaFieldEmptyReturnsString(): void {
    $entity = $this->createMockEntity(ContentEntityInterface::class, [
      'field_description' => [],
    ]);
    $result = $this->entityHelper->getTextareaField($entity, 'description');
    $this->assertSame('', $result);
    $this->assertIsString($result);
  }

  /**
   * Structural helpers (getDoubleField) must return [] (not '') when empty.
   *
   * This catches the normalizeReturnValue default change from '' to [].
   * Before the fix, empty structural fields returned '' which broke array consumers.
   *
   * @covers ::getDoubleField
   */
  public function testGetDoubleFieldEmptyReturnsArray(): void {
    $entity = $this->createMockEntity(ContentEntityInterface::class, [
      'field_dimensions' => [],
    ]);
    $result = $this->entityHelper->getDoubleField($entity, 'dimensions');
    $this->assertSame([], $result);
    $this->assertIsArray($result);
  }

  // ---------------------------------------------------------------
  // mapFields passthrough value tests
  // ---------------------------------------------------------------

  /**
   * Verify non-string passthrough values (int, bool, NULL, 0, float).
   *
   * Strings are always treated as field names. Non-string values pass through.
   * This is why real code uses closures or pre-computed expressions for URLs.
   *
   * @covers ::mapFields
   */
  public function testMapFieldsPassthroughNonStringTypes(): void {
    $entity = $this->createMockEntityWithCardinality(
      ContentEntityInterface::class,
      ['field_title' => [['value' => 'Blog Post']]],
      ['field_title' => ['type' => 'string', 'cardinality' => 1]],
    );
    $result = $this->entityHelper->mapFields($entity, [
      'title' => 'title',
      'date' => 1700000000,
      'active' => TRUE,
      'inactive' => FALSE,
      'count' => 0,
      'price' => 29.99,
      'nothing' => NULL,
    ]);
    $this->assertSame('Blog Post', $result['title']);
    $this->assertSame(1700000000, $result['date']);
    $this->assertTrue($result['active']);
    $this->assertFalse($result['inactive']);
    $this->assertSame(0, $result['count']);
    $this->assertSame(29.99, $result['price']);
    $this->assertNull($result['nothing']);
  }

  /**
   * Strings that don't match any field are returned as literal values.
   *
   * This allows passing config values, URLs, labels etc. directly in mapFields
   * without needing closures or separate assignments.
   *
   * @covers ::mapFields
   */
  public function testMapFieldsStringLiteralWhenFieldNotFound(): void {
    $entity = $this->createMockEntityWithCardinality(
      ContentEntityInterface::class,
      ['field_title' => [['value' => 'Hello']]],
      ['field_title' => ['type' => 'string', 'cardinality' => 1]],
    );

    // URL string doesn't match any field — returned as literal.
    $result = $this->entityHelper->mapFields($entity, [
      'url' => '/blog/post-1',
      'api_key' => 'this-is-a-test-placeholder-not-a-real-key',
      'title' => 'title',
    ]);
    $this->assertSame('/blog/post-1', $result['url']);
    $this->assertSame('this-is-a-test-placeholder-not-a-real-key', $result['api_key']);
    // 'title' matches field_title — resolved as field value.
    $this->assertSame('Hello', $result['title']);
  }

  // ---------------------------------------------------------------
  // collectCacheMetadata tests
  // ---------------------------------------------------------------

  /**
   * Dot-notation child fields are called without auto-cardinality params.
   *
   * mapDotNotation does not call buildFieldParams on child fields, matching
   * the formatFields() path used by mapStringConfig for
   * entity_reference_revisions. This prevents double nesting of media
   * fields (e.g. [[{src,type,alt}]] instead of [{src,type,alt}]).
   *
   * @covers ::mapFields
   */
  public function testMapFieldsDotNotationNoAutoArrayOnChildFields(): void {
    $helper = $this->createPartialHelper(['getEntityReferenceField', 'formatField']);

    // Child with field_media: storage cardinality -1 (shared), but
    // instance_cardinality 1 (single-value per field_config_cardinality).
    $child = $this->createMockEntityWithCardinality(
      ContentEntityInterface::class,
      [
        'field_media' => [['target_id' => 1]],
        'field_title' => [['value' => 'Badge']],
      ],
      [
        'field_media' => [
          'type' => 'entity_reference',
          'cardinality' => -1,
          'instance_cardinality' => 1,
          'settings' => ['handler' => 'default:media'],
        ],
        'field_title' => ['type' => 'string', 'cardinality' => 1],
      ],
    );

    $entity = $this->createMockEntityWithCardinality(
      ContentEntityInterface::class,
      ['field_paragraphs' => [['target_id' => 1]]],
      [
        'field_paragraphs' => [
          'type' => 'entity_reference_revisions',
          'cardinality' => -1,
        ],
      ],
    );

    $helper->method('getEntityReferenceField')
      ->willReturn([$child]);

    // Track actual params passed to formatField.
    $called_params = [];
    $helper->method('formatField')
      ->willReturnCallback(function ($ent, $field, $params = []) use ($child, &$called_params) {
        $called_params[$field] = $params;
        if ($ent === $child && $field === 'media') {
          // Simulate getMediaField single-value return (no return_format).
          return [['src' => '/img.jpg', 'type' => 'image/jpeg', 'alt' => '']];
        }
        if ($ent === $child && $field === 'title') {
          return 'Badge';
        }
        return FALSE;
      });

    $result = $helper->mapFields($entity, [
      'images' => [
        'image' => 'paragraphs.media',
        'badge' => 'paragraphs.title',
      ],
    ]);

    // media: instance_cardinality=1 → empty params (no return_format).
    $this->assertSame([], $called_params['media'], 'Child media field must not receive return_format=array params');
    $this->assertSame([], $called_params['title'], 'Child title field must not receive return_format=array params');

    // Image data must not be double-nested.
    $this->assertCount(1, $result['images']);
    $image = $result['images'][0]['image'];
    $this->assertSame('/img.jpg', $image[0]['src'], 'Image src must be at [0].src, not [0][0].src');
    $this->assertSame('Badge', $result['images'][0]['badge']);
  }

  /**
   * Dot-notation child fields never receive buildFieldParams.
   *
   * Dot-notation child fields receive auto-cardinality params from
   * buildFieldParams, consistent with mapStringConfig. This ensures
   * multi-value fields always return arrays (no single-item unwrapping).
   *
   * @covers ::mapFields
   */
  public function testMapFieldsDotNotationPassesBuildFieldParams(): void {
    $helper = $this->createPartialHelper(['getEntityReferenceField', 'formatField']);

    // Child with field_media2: storage and instance cardinality both -1.
    $child = $this->createMockEntityWithCardinality(
      ContentEntityInterface::class,
      [
        'field_media2' => [['target_id' => 1]],
      ],
      [
        'field_media2' => [
          'type' => 'entity_reference',
          'cardinality' => -1,
          'instance_cardinality' => -1,
          'settings' => ['handler' => 'default:media'],
        ],
      ],
    );

    $entity = $this->createMockEntityWithCardinality(
      ContentEntityInterface::class,
      ['field_paragraphs' => [['target_id' => 1]]],
      [
        'field_paragraphs' => [
          'type' => 'entity_reference_revisions',
          'cardinality' => -1,
        ],
      ],
    );

    $helper->method('getEntityReferenceField')
      ->willReturn([$child]);

    $called_params = [];
    $helper->method('formatField')
      ->willReturnCallback(function ($ent, $field, $params = []) use ($child, &$called_params) {
        $called_params[$field] = $params;
        if ($ent === $child && $field === 'media2') {
          // Simulate getMediaField with return_format => array: returns
          // a double array (multivalue field of image sources).
          return [['src' => '/gallery.jpg', 'type' => 'image/jpeg', 'alt' => '']];
        }
        return FALSE;
      });

    $result = $helper->mapFields($entity, [
      'items' => [
        'images' => 'paragraphs.media2',
      ],
    ]);

    // Dot-notation child fields receive auto-cardinality params.
    $this->assertSame(
      ['return_format' => 'array'],
      $called_params['media2'],
      'Dot-notation child field must receive auto-cardinality params'
    );

    // Image data: multivalue array preserved for single item.
    $images = $result['items'][0]['images'];
    $this->assertIsArray($images);
    $this->assertSame('/gallery.jpg', $images[0]['src']);
  }

  public function testCollectCacheMetadataReturnsAndResets(): void {
    // Fresh helper should have empty cache metadata.
    $cache = $this->entityHelper->collectCacheMetadata();
    $this->assertEmpty($cache->getCacheTags());

    // Second call should also be empty (confirms reset).
    $cache2 = $this->entityHelper->collectCacheMetadata();
    $this->assertEmpty($cache2->getCacheTags());
  }

  // ---------------------------------------------------------------
  // getTaxonomy tests — delegated to TaxonomyTreeBuilder.
  // Builder-level coverage lives in TaxonomyTreeBuilderTest; the test
  // here asserts the EntityHelper facade contract: delegate to the
  // builder and bubble its cache metadata into the EntityHelper
  // collector.
  // ---------------------------------------------------------------

  /**
   * Set up the language manager to return a fixed langcode.
   *
   * Retained because the menu tests below still use it.
   */
  protected function stubCurrentLanguage(string $langcode = 'en'): void {
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn($langcode);
    $this->languageManager->method('getCurrentLanguage')->willReturn($language);
  }

  /**
   * @covers ::getTaxonomy
   */
  public function testGetTaxonomyReturnsBuilderResultAndBubblesMetadata(): void {
    $expected_items = [
      ['id' => 1, 'title' => 'Alpha', 'url' => '/taxonomy/term/1'],
      ['id' => 2, 'title' => 'Beta', 'url' => '/taxonomy/term/2'],
    ];

    $builder_metadata = (new CacheableMetadata())
      ->addCacheTags(['taxonomy_term:1', 'taxonomy_term_list:cats']);

    $builder = $this->createMock(TaxonomyTreeBuilder::class);
    $builder->expects($this->once())
      ->method('build')
      ->with('cats', ['nested' => FALSE])
      ->willReturn($expected_items);
    $builder->expects($this->once())
      ->method('collectCacheMetadata')
      ->willReturn($builder_metadata);

    $helper = $this->createHelperWithOverrides([
      'taxonomy_tree_builder' => $builder,
    ]);

    $items = $helper->getTaxonomy('cats', ['nested' => FALSE]);
    $this->assertSame($expected_items, $items);

    // EntityHelper's own collector must contain what the builder
    // bubbled up.
    $tags = $helper->collectCacheMetadata()->getCacheTags();
    $this->assertContains('taxonomy_term:1', $tags);
    $this->assertContains('taxonomy_term_list:cats', $tags);
  }

  // ---------------------------------------------------------------
  // (former in-EntityHelperTest taxonomy fixtures retained below
  // only because Resizer/term helpers cross-reference them — left
  // for the next builder extractions to clean up.)
  // ---------------------------------------------------------------

  /**
   * Build a stub taxonomy_term storage that returns the given term tree.
   */
  protected function stubTaxonomyTermStorage(array $terms, string $entity_type_id = 'taxonomy_term'): void {
    $storage = $this->createMock(TermStorageInterface::class);
    $storage->method('loadTree')->willReturn($terms);
    $storage->method('getEntityTypeId')->willReturn($entity_type_id);
    $this->entityTypeManager
      ->method('getStorage')
      ->with('taxonomy_term')
      ->willReturn($storage);
  }

  /**
   * Build a mock term with the minimum API used by the builder.
   */
  protected function makeTerm(int $tid, string $label, bool $published = TRUE): TermInterface {
    $term = $this->createMock(TermInterface::class);
    $term->method('id')->willReturn((string) $tid);
    $term->method('isPublished')->willReturn($published);
    $term->method('hasTranslation')->willReturn(TRUE);
    $term->method('getTranslation')->willReturnSelf();
    $term->method('label')->willReturn($label);
    $url = $this->createMock(Url::class);
    $url->method('toString')->willReturn('/taxonomy/term/' . $tid);
    $term->method('toUrl')->willReturn($url);
    // Cache metadata surface (consumed via addCacheableDependency).
    $term->method('getCacheTags')->willReturn(['taxonomy_term:' . $tid]);
    $term->method('getCacheContexts')->willReturn([]);
    $term->method('getCacheMaxAge')->willReturn(Cache::PERMANENT);
    // Parent field (returns no parent so buildTermTree attaches to root 0).
    $parent_list = $this->createMock(FieldItemListInterface::class);
    $parent_list->method('getValue')->willReturn([]);
    $term->method('get')->with('parent')->willReturn($parent_list);
    return $term;
  }

  // ---------------------------------------------------------------
  // getMenu tests — delegated to MenuTreeBuilder.
  // Builder-level coverage lives in MenuTreeBuilderTest; here we
  // assert only the facade contract: delegate to the builder, pass
  // the formatField callback for menu-link-extras enrichment, and
  // bubble the builder's cache metadata into the EntityHelper
  // collector.
  // ---------------------------------------------------------------

  /**
   * @covers ::getMenu
   */
  public function testGetMenuReturnsBuilderResultAndBubblesMetadata(): void {
    $expected_items = [
      ['id' => 1, 'title' => 'Home', 'url' => '/', 'below' => []],
    ];

    $builder_metadata = (new CacheableMetadata())
      ->addCacheTags(['node:42'])
      ->addCacheContexts(['user.permissions']);

    $builder = $this->createMock(MenuTreeBuilder::class);
    $builder->expects($this->once())
      ->method('build')
      ->willReturnCallback(function ($menu_name, $params, $formatter) use ($expected_items) {
        $this->assertSame('main', $menu_name);
        $this->assertSame([], $params);
        // formatField is wired as the enrichment callback so menu link
        // extras fields render.
        $this->assertIsCallable($formatter);
        return $expected_items;
      });
    $builder->expects($this->once())
      ->method('collectCacheMetadata')
      ->willReturn($builder_metadata);

    $helper = $this->createHelperWithOverrides([
      'menu_tree_builder' => $builder,
    ]);

    $items = $helper->getMenu('main');
    $this->assertSame($expected_items, $items);

    $cache = $helper->collectCacheMetadata();
    $this->assertContains('node:42', $cache->getCacheTags());
    $this->assertContains('user.permissions', $cache->getCacheContexts());
  }

  // ---------------------------------------------------------------
  // getTextareaField cache metadata tests
  // ---------------------------------------------------------------

  /**
   * Cache metadata from text format filters reaches the collector.
   *
   * Text format filters (media_embed, editor_file_reference, linkit,
   * token) attach referenced-entity cache metadata during rendering.
   * renderInIsolation() contains the bubbling but leaves the metadata
   * on the passed render array. getTextareaField() must forward that
   * metadata so WYSIWYG-embedded media/files invalidate correctly.
   *
   * @covers ::getTextareaField
   */
  public function testGetTextareaFieldBubblesFilterCacheMetadataIntoCollector(): void {
    // Simulate a text format filter that attaches referenced-entity tags.
    $renderer = $this->createMock(RendererInterface::class);
    $renderer->method('renderInIsolation')
      ->willReturnCallback(function (&$elements) {
        $elements['#cache'] = [
          'contexts' => ['user.permissions'],
          'tags' => ['media:42', 'file:15'],
          'max-age' => Cache::PERMANENT,
        ];
        return '<p>rendered</p>';
      });

    $helper = $this->createHelperWithOverrides(['renderer' => $renderer]);

    $entity = $this->createMockEntity(ContentEntityInterface::class, [
      'field_content' => [['value' => 'Hello', 'format' => 'full_html']],
    ]);

    $result = $helper->getTextareaField($entity, 'content');
    $this->assertNotEmpty($result);

    $cache = $helper->collectCacheMetadata();
    $tags = $cache->getCacheTags();
    $this->assertContains('media:42', $tags, 'media_embed filter tags must bubble');
    $this->assertContains('file:15', $tags, 'editor_file_reference filter tags must bubble');
    $this->assertContains('user.permissions', $cache->getCacheContexts());
  }

  /**
   * @covers ::getTextareaField
   */
  public function testGetTextareaFieldBubblesMetadataForEachMultivalueItem(): void {
    // Simulate two text-format renders attaching distinct metadata per item.
    $call = 0;
    $renderer = $this->createMock(RendererInterface::class);
    $renderer->method('renderInIsolation')
      ->willReturnCallback(function (&$elements) use (&$call) {
        $call++;
        $elements['#cache'] = [
          'contexts' => [],
          'tags' => ['media:' . $call],
          'max-age' => Cache::PERMANENT,
        ];
        return '<p>rendered ' . $call . '</p>';
      });

    $helper = $this->createHelperWithOverrides(['renderer' => $renderer]);

    $entity = $this->createMockEntity(ContentEntityInterface::class, [
      'field_content' => [
        ['value' => 'First', 'format' => 'full_html'],
        ['value' => 'Second', 'format' => 'full_html'],
      ],
    ]);

    $helper->getTextareaField($entity, 'content');

    $tags = $helper->collectCacheMetadata()->getCacheTags();
    $this->assertContains('media:1', $tags, 'First item metadata must bubble');
    $this->assertContains('media:2', $tags, 'Second item metadata must bubble');
  }

}

// phpcs:disable
/**
 * Minimal stub implementing FieldItemListInterface for unit testing.
 */
class StubFieldItemList extends \ArrayObject implements \Drupal\Core\Field\FieldItemListInterface {

  public function __construct(array $items) {
    $objects = [];
    foreach ($items as $item) {
      $obj = new \stdClass();
      foreach ($item as $key => $val) {
        $obj->{$key} = $val;
      }
      $objects[] = $obj;
    }
    parent::__construct($objects);
  }

  public function getEntity() { return NULL; }
  public function setLangcode($langcode) {}
  public function getLangcode() { return 'en'; }
  public function getFieldDefinition() { return NULL; }
  public function getSettings() { return []; }
  public function getSetting($setting_name) { return NULL; }
  public function defaultAccess($operation = 'view', ?\Drupal\Core\Session\AccountInterface $account = NULL) { return TRUE; }
  public function filterEmptyItems() { return $this; }
  public function __get($property_name) { return NULL; }
  public function __set($property_name, $value) {}
  public function __isset($property_name) { return FALSE; }
  public function __unset($property_name) {}
  public function preSave() {}
  public function postSave($update) {}
  public function delete() {}
  public function deleteRevision() {}
  public function view($display_options = []) { return []; }
  public function generateSampleItems($count = 1) { return $this; }
  public function defaultValuesForm(array &$form, \Drupal\Core\Form\FormStateInterface $form_state) { return []; }
  public function defaultValuesFormValidate(array $element, array &$form, \Drupal\Core\Form\FormStateInterface $form_state) {}
  public function defaultValuesFormSubmit(array $element, array &$form, \Drupal\Core\Form\FormStateInterface $form_state) { return []; }
  public static function processDefaultValue($default_value, \Drupal\Core\Entity\FieldableEntityInterface $entity, \Drupal\Core\Field\FieldDefinitionInterface $definition) { return NULL; }
  public function equals(\Drupal\Core\Field\FieldItemListInterface $list_to_compare) { return FALSE; }
  public function hasAffectingChanges(\Drupal\Core\Field\FieldItemListInterface $original_items, $langcode) { return FALSE; }
  public function getDataDefinition() { return NULL; }
  public function isEmpty() { return $this->count() === 0; }
  public function getItemDefinition() { return NULL; }
  public function get($index) { return $this[$index] ?? NULL; }
  public function set($index, $value) { return $this; }
  public function first() { return $this[0] ?? NULL; }
  public function last(): ?\Drupal\Core\TypedData\TypedDataInterface { return $this[count($this) - 1] ?? NULL; }
  public function appendItem($value = NULL) { return NULL; }
  public function removeItem($index) {}
  public function filter($callback) { return $this; }
  public function onChange($name) {}
  public static function createInstance($definition, $name = NULL, ?\Drupal\Core\TypedData\TraversableTypedDataInterface $parent = NULL) { return NULL; }
  public function getValue() { return []; }
  public function setValue($value, $notify = TRUE) {}
  public function getString() {
    $first = $this[0] ?? NULL;
    return ($first !== NULL && isset($first->value)) ? (string) $first->value : '';
  }
  public function getConstraints() { return []; }
  public function validate() { return new \Symfony\Component\Validator\ConstraintViolationList(); }
  public function applyDefaultValue($notify = TRUE) { return $this; }
  public function getName() { return ''; }
  public function getParent() { return NULL; }
  public function getRoot() { return $this; }
  public function getPropertyPath() { return ''; }
  public function setContext($name = NULL, ?\Drupal\Core\TypedData\TraversableTypedDataInterface $parent = NULL) {}
  public function access($operation, ?\Drupal\Core\Session\AccountInterface $account = NULL, $return_as_object = FALSE) { return TRUE; }
  public function getProperties($include_computed = FALSE) { return []; }
  public function toArray() { return iterator_to_array($this); }

}
// phpcs:enable
