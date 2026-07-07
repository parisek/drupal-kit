<?php

namespace Drupal\custom_components\Services;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\office_hours\OfficeHoursDateHelper;
use Drupal\comment\CommentInterface;
use Drupal\user\UserInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\media\MediaInterface;
use Drupal\file\FileInterface;
use Drupal\link\LinkItemInterface;
use Drupal\system\MenuInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Locale\CountryManager;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Image\ImageFactory;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Used for accessing entity data.
 */
class EntityHelper {

  use StringTranslationTrait;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The entity repository service.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * The menu link tree service.
   *
   * @var \Drupal\Core\Menu\MenuLinkTreeInterface
   */
  protected $menuLinkTree;

  /**
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The image factory.
   *
   * @var \Drupal\Core\Image\ImageFactory
   */
  protected $imageFactory;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Resolves the active trail for menus, with breadcrumb fallback.
   */
  protected MenuActiveTrailResolver $menuActiveTrailResolver;

  /**
   * Builds taxonomy term arrays (delegated from getTaxonomy()).
   */
  protected TaxonomyTreeBuilder $taxonomyTreeBuilder;

  /**
   * Builds menu trees (delegated from getMenu()).
   */
  protected MenuTreeBuilder $menuTreeBuilder;

  /**
   * Builds Media/File render arrays for generateMedia() and generateFile().
   */
  protected MediaArrayBuilder $mediaArrayBuilder;

  /**
   * Accumulated cacheable metadata from entity-loading methods.
   *
   * @var \Drupal\Core\Cache\CacheableMetadata
   */
  protected CacheableMetadata $cacheMetadata;

  /**
   * Constructs a new EntityHelper object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    RouteMatchInterface $route_match,
    LanguageManagerInterface $language_manager,
    EntityRepositoryInterface $entity_repository,
    ConfigFactoryInterface $config_factory,
    Connection $connection,
    CacheBackendInterface $cache,
    MenuLinkTreeInterface $menu_link_tree,
    FileUrlGeneratorInterface $file_url_generator,
    LoggerChannelFactoryInterface $logger_factory,
    RendererInterface $renderer,
    DateFormatterInterface $date_formatter,
    ImageFactory $image_factory,
    RequestStack $request_stack,
    MenuActiveTrailResolver $menu_active_trail_resolver,
    TaxonomyTreeBuilder $taxonomy_tree_builder,
    MenuTreeBuilder $menu_tree_builder,
    MediaArrayBuilder $media_array_builder,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->routeMatch = $route_match;
    $this->languageManager = $language_manager;
    $this->entityRepository = $entity_repository;
    $this->configFactory = $config_factory;
    $this->connection = $connection;
    $this->cache = $cache;
    $this->menuLinkTree = $menu_link_tree;
    $this->fileUrlGenerator = $file_url_generator;
    $this->loggerFactory = $logger_factory;
    $this->renderer = $renderer;
    $this->dateFormatter = $date_formatter;
    $this->imageFactory = $image_factory;
    $this->requestStack = $request_stack;
    $this->menuActiveTrailResolver = $menu_active_trail_resolver;
    $this->taxonomyTreeBuilder = $taxonomy_tree_builder;
    $this->menuTreeBuilder = $menu_tree_builder;
    $this->mediaArrayBuilder = $media_array_builder;
    $this->cacheMetadata = new CacheableMetadata();
  }

  /**
   * Collect accumulated cache metadata and reset for next call.
   *
   * @return \Drupal\Core\Cache\CacheableMetadata
   *   The accumulated cacheable metadata.
   */
  public function collectCacheMetadata(): CacheableMetadata {
    $cache = $this->cacheMetadata;
    $this->cacheMetadata = new CacheableMetadata();
    return $cache;
  }

  /**
   * Add cache tags to the accumulated metadata.
   *
   * @param string[] $tags
   *   Cache tags to add.
   */
  public function addCacheTags(array $tags): void {
    $this->cacheMetadata->addCacheTags($tags);
  }

  /**
   * Normalize a field name by adding 'field_' prefix if missing.
   *
   * @param string $field_name
   *   The field name to normalize.
   * @param object|null $entity
   *   The entity, used to check exempt field/entity-type combinations.
   * @param array $exempt
   *   Map of field_name => entity interface class that should skip prefixing.
   *
   * @return string
   *   The normalized field name.
   */
  protected function normalizeFieldName(string $field_name, $entity = NULL, array $exempt = []): string {
    foreach ($exempt as $exempt_name => $exempt_class) {
      if ($field_name === $exempt_name && $entity instanceof $exempt_class) {
        return $field_name;
      }
    }
    // If entity provided and field exists as-is, don't add prefix.
    if ($entity !== NULL && $entity->hasField($field_name)) {
      return $field_name;
    }
    if (substr($field_name, 0, 6) !== 'field_') {
      $field_name = 'field_' . $field_name;
    }
    return $field_name;
  }

  /**
   * Validate that an entity has the given field.
   *
   * @param object $entity
   *   The entity to check.
   * @param string $field_name
   *   The field name to check for.
   *
   * @return bool
   *   TRUE if the entity has the field.
   */
  protected function validateField($entity, string $field_name): bool {
    return $entity->hasField($field_name);
  }

  /**
   * Normalize a return value from an array of collected items.
   *
   * @param array $items
   *   The collected items.
   * @param array $params
   *   Parameters that may include 'return_format' => 'array'.
   * @param mixed $empty_default
   *   Value to return when items is empty (default: []).
   *
   * @return mixed
   *   Unwrapped single value, array, or the empty default.
   */
  protected function normalizeReturnValue(array $items, array $params = [], $empty_default = []) {
    if (isset($params['return_format']) && $params['return_format'] === 'array') {
      return (array) $items;
    }
    if (count($items) === 1) {
      return reset($items);
    }
    if (count($items) === 0) {
      return $empty_default;
    }
    return $items;
  }

  /**
   * Auto-detect a field's type and dispatch to the appropriate getter.
   *
   * @param object $entity
   *   The entity to read from.
   * @param string $field_name
   *   The field name (with or without 'field_' prefix).
   * @param array $params
   *   Optional parameters forwarded to the underlying getter.
   *
   * @return mixed
   *   The formatted field value, or FALSE if the field doesn't exist.
   */
  public function formatField($entity, string $field_name, array $params = []) {
    $resolved = $this->resolveFieldName($entity, $field_name);
    if ($resolved === NULL) {
      return FALSE;
    }

    $field_definition = $entity->getFieldDefinition($resolved);
    if (!$field_definition instanceof FieldDefinitionInterface) {
      return FALSE;
    }

    return $this->dispatchByFieldType($entity, $resolved, $field_definition, $params);
  }

  /**
   * Format all fields on an entity in one call.
   *
   * Custom field_* fields are dispatched through formatField() for type-aware
   * formatting. Base fields (nid, status, langcode, etc.) use getString()
   * for raw scalar values. Empty fields are skipped.
   *
   * @param object $entity
   *   The entity to read from.
   * @param array $params
   *   Optional parameters forwarded to formatField() for field_* fields.
   *
   * @return array
   *   Keyed array of formatted values. field_* keys have the prefix stripped.
   */
  public function formatFields($entity, array $params = []): array {
    $result = [];
    $field_definitions = $entity->getFieldDefinitions();

    foreach ($field_definitions as $field_name => $field_definition) {
      if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
        continue;
      }

      if (strpos($field_name, 'field_') === 0) {
        $key = substr($field_name, 6);
        $result[$key] = $this->formatField($entity, $field_name, $params);
      }
      else {
        $result[$field_name] = $entity->get($field_name)->getString();
      }
    }

    return $result;
  }

  /**
   * Map entity fields to a content array using a declarative mapping.
   *
   * When $map is empty, delegates to formatFields() for auto-formatting.
   * String configs auto-detect field type: entity_reference_revisions become
   * nested items (children auto-formatted), everything else uses formatField().
   * Array configs support dot notation for explicit child mapping, explicit
   * method overrides, and custom params. In array configs only 'field' is
   * reserved; all other keys become params merged on top of auto-cardinality.
   *
   * Cache tags from referenced entities accumulate in $this->cacheMetadata.
   * Retrieve them via collectCacheMetadata() after calling this method.
   *
   * @param object $entity
   *   The entity to extract from.
   * @param array $map
   *   Mapping definition. Empty = auto-format all field_* fields.
   *
   * @return array
   *   Keyed array of extracted values.
   */
  public function mapFields($entity, array $map = []): array {
    if (empty($map)) {
      return $this->formatFields($entity);
    }

    $result = [];
    foreach ($map as $content_key => $config) {
      if (is_string($config)) {
        $result[$content_key] = $this->mapStringConfig($entity, $config);
      }
      elseif (is_array($config)) {
        $result[$content_key] = $this->mapArrayConfig($entity, $config, $content_key);
      }
      elseif ($config instanceof \Closure) {
        $result[$content_key] = $config($entity);
      }
      else {
        $result[$content_key] = $config;
      }
    }
    return $result;
  }

  /**
   * Process a string mapping config value.
   *
   * If the field type is entity_reference_revisions, loads children and
   * auto-formats each child's fields. Otherwise, uses formatField() with
   * auto-detected cardinality.
   *
   * @param object $entity
   *   The entity.
   * @param string $config
   *   The field name string.
   *
   * @return mixed
   *   The extracted value.
   */
  private function mapStringConfig($entity, string $config) {
    $resolved = $this->resolveFieldName($entity, $config);

    // String doesn't match any field — treat as literal value.
    if (!$resolved) {
      return $config;
    }

    $definition = $entity->getFieldDefinition($resolved);
    $type = $definition ? $definition->getType() : '';
    if ($type === 'entity_reference_revisions') {
      $children = $this->getEntityReferenceField($entity, $config, ['return_format' => 'array']);
      $items = [];
      if (is_array($children)) {
        foreach ($children as $child) {
          $items[] = $this->formatFields($child);
        }
      }
      return $items;
    }

    $params = $this->buildFieldParams($entity, $config);
    return $this->formatField($entity, $config, $params);
  }

  /**
   * Process an array mapping config value.
   *
   * Handles four patterns:
   * 1. Explicit method override:
   *    ['method' => 'getDoubleField', 'field' => ...].
   * 2. Dot notation for nested child mapping: ['key' => 'ref.child_field'].
   * 3. Sub-object map: content_key doesn't resolve to a field on the entity,
   *    so each value is treated as a field name to extract into a sub-array.
   * 4. Single field with custom params. Only 'field' is reserved; all other
   *    keys become params, merged on top of auto-cardinality defaults.
   *
   * @param object $entity
   *   The entity.
   * @param array $config
   *   The array config.
   * @param string $content_key
   *   The output key name (used as fallback field name).
   *
   * @return mixed
   *   The extracted value.
   */
  private function mapArrayConfig($entity, array $config, string $content_key) {
    // List arrays (sequential numeric keys) are pre-built data — pass through.
    if (array_is_list($config)) {
      return $config;
    }

    // 1. Explicit method override.
    if (isset($config['method'])) {
      $field = $config['field'] ?? $content_key;
      $params = $config['params'] ?? [];
      return $this->{$config['method']}($entity, $field, ...$params);
    }

    // 2. Dot notation: nested child mapping.
    $first_string = NULL;
    foreach ($config as $v) {
      if (is_string($v)) {
        $first_string = $v;
        break;
      }
    }
    if ($first_string !== NULL && str_contains($first_string, '.') && preg_match('/^[a-z_][a-z0-9_.]*$/', $first_string)) {
      return $this->mapDotNotation($entity, $config);
    }

    // 3. Sub-object map: content_key is not a real field, values are field
    // names to extract from the entity into a nested array.
    if (!isset($config['field'])) {
      $resolved = $this->resolveFieldName($entity, $content_key);
      if ($resolved === NULL) {
        $sub = [];
        foreach ($config as $sub_key => $field_name) {
          if (is_string($field_name)) {
            $sub[$sub_key] = $this->mapStringConfig($entity, $field_name);
          }
          elseif ($field_name instanceof \Closure) {
            $sub[$sub_key] = $field_name($entity);
          }
          elseif (is_array($field_name)) {
            $sub[$sub_key] = $this->mapArrayConfig($entity, $field_name, $sub_key);
          }
          else {
            $sub[$sub_key] = $field_name;
          }
        }
        return $sub;
      }
    }

    // 4. Single field with custom params.
    // Only 'field' is reserved. Everything else becomes params,
    // merged on top of auto-cardinality defaults.
    $field = $config['field'] ?? $content_key;
    $params = array_diff_key($config, array_flip(['field']));
    $params = array_merge($this->buildFieldParams($entity, $field), $params);
    return $this->formatField($entity, $field, $params);
  }

  /**
   * Process dot notation config for nested child field mapping.
   *
   * Extracts the reference field from the first value (before the dot),
   * loads child entities, and maps specified fields from each child.
   *
   * @param object $entity
   *   The parent entity.
   * @param array $config
   *   Map of output_key => 'reference_field.child_field'.
   *   Left-side dot wraps array elements: 'images.image' => 'ref.media'
   *   produces [['image' => v1], ['image' => v2]].
   *
   * @return array
   *   Array of mapped child items.
   */
  private function mapDotNotation($entity, array $config): array {
    $ref_field = NULL;
    foreach ($config as $v) {
      if (is_string($v) && str_contains($v, '.')) {
        [$ref_field] = explode('.', $v, 2);
        break;
      }
    }
    $children = $this->getEntityReferenceField($entity, $ref_field, ['return_format' => 'array']);
    $items = [];
    if (is_array($children)) {
      foreach ($children as $child) {
        $item = [];
        foreach ($config as $key => $dot_path) {
          if ($dot_path instanceof \Closure) {
            $item[$key] = $dot_path($child);
            continue;
          }
          if (!str_contains($dot_path, '.')) {
            $item[$key] = $dot_path;
            continue;
          }
          [, $child_field] = explode('.', $dot_path, 2);
          $params = $this->buildFieldParams($child, $child_field);
          $value = $this->formatField($child, $child_field, $params);

          // Left-side dot: 'images.image' => wrap each array element.
          if (str_contains($key, '.')) {
            [$parent_key, $wrap_key] = explode('.', $key, 2);
            if (is_array($value)) {
              $value = array_map(fn($v) => [$wrap_key => $v], $value);
            }
            $item[$parent_key] = $value;
          }
          else {
            $item[$key] = $value;
          }
        }
        $items[] = $item;
      }
    }
    return $items;
  }

  /**
   * Build default params based on field cardinality.
   *
   * @param object $entity
   *   The entity.
   * @param string $field_name
   *   The field name.
   *
   * @return array
   *   Params array with 'return_format' => 'array' if cardinality != 1.
   */
  private function buildFieldParams($entity, string $field_name): array {
    $cardinality = $this->getEffectiveCardinality($entity, $field_name);
    if ($cardinality !== 1) {
      return ['return_format' => 'array'];
    }
    return [];
  }

  /**
   * Determine the effective cardinality for a field.
   *
   * Checks per-instance cardinality override first, then falls back to
   * storage cardinality.
   *
   * @param object $entity
   *   The entity.
   * @param string $field_name
   *   The field name.
   *
   * @return int
   *   The effective cardinality (1 = single, -1 = unlimited, N = fixed).
   */
  private function getEffectiveCardinality($entity, string $field_name): int {
    $resolved = $this->resolveFieldName($entity, $field_name);
    if (!$resolved) {
      return 1;
    }
    $definition = $entity->getFieldDefinition($resolved);
    if (!$definition) {
      return 1;
    }
    if (method_exists($definition, 'getThirdPartySetting')) {
      $instance = $definition->getThirdPartySetting('field_config_cardinality', 'cardinality_config');
      if ($instance !== NULL) {
        return (int) $instance;
      }
    }
    return $definition->getFieldStorageDefinition()->getCardinality();
  }

  /**
   * Resolve a field name to the actual field present on the entity.
   *
   * @param object $entity
   *   The entity to check.
   * @param string $field_name
   *   The field name to resolve.
   *
   * @return string|null
   *   The resolved field name, or NULL if not found.
   */
  private function resolveFieldName($entity, string $field_name): ?string {
    if ($entity->hasField($field_name)) {
      return $field_name;
    }
    $prefixed = 'field_' . $field_name;
    if ($entity->hasField($prefixed)) {
      return $prefixed;
    }
    return NULL;
  }

  /**
   * Route to the correct getter based on field type.
   *
   * @param object $entity
   *   The entity.
   * @param string $field_name
   *   Short field name (without 'field_' prefix).
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   * @param array $params
   *   Parameters to forward.
   *
   * @return mixed
   *   The formatted field value.
   */
  private function dispatchByFieldType($entity, string $field_name, FieldDefinitionInterface $field_definition, array $params) {
    $type = $field_definition->getType();

    switch ($type) {
      case 'string':
      case 'string_long':
      case 'email':
      case 'telephone':
      case 'integer':
      case 'decimal':
      case 'float':
        return $this->getTextField($entity, $field_name, $params);

      case 'text':
      case 'text_long':
      case 'text_with_summary':
        return $this->getTextareaField($entity, $field_name);

      case 'boolean':
        return $this->getBooleanField($entity, $field_name);

      case 'link':
        return $this->getLinkField($entity, $field_name, $params);

      case 'list_string':
      case 'list_integer':
        return $this->getSelectField($entity, $field_name);

      case 'image':
        return $this->getImageField($entity, $field_name, $params);

      case 'file':
        return $this->getFileField($entity, $field_name);

      case 'datetime':
        return $this->getDateField($entity, $field_name);

      case 'daterange':
        return $this->getDateRangeField($entity, $field_name);

      case 'address':
        return $this->getAddressField($entity, $field_name);

      case 'geofield':
        return $this->getGeoField($entity, $field_name);

      case 'office_hours':
        return $this->getOfficeHoursField($entity, $field_name);

      case 'double_field':
        return $this->getDoubleField($entity, $field_name, $params);

      case 'commerce_price':
        return $this->getPriceField($entity, $field_name);

      case 'webform':
        return $this->getWebformField($entity, $field_name);

      case 'entity_reference':
      case 'entity_reference_revisions':
        return $this->resolveEntityReference($entity, $field_name, $field_definition, $params);

      default:
        return $this->getTextField($entity, $field_name, $params);
    }
  }

  /**
   * Route entity_reference fields based on handler setting.
   *
   * @param object $entity
   *   The entity.
   * @param string $field_name
   *   Short field name.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   * @param array $params
   *   Parameters to forward.
   *
   * @return mixed
   *   The formatted reference value.
   */
  private function resolveEntityReference($entity, string $field_name, FieldDefinitionInterface $field_definition, array $params) {
    $settings = $field_definition->getSettings();
    $handler = $settings['handler'] ?? '';

    switch ($handler) {
      case 'default:media':
        return $this->getMediaField($entity, $field_name, $params);

      case 'default:taxonomy_term':
        return $this->getTermField($entity, $field_name, $params);

      case 'default:webform':
        return $this->getWebformField($entity, $field_name);

      default:
        return $this->getEntityReferenceField($entity, $field_name, $params);
    }
  }

  /**
   * Generate data from a remote video media entity.
   */
  public function generateMediaRemoteVideo(MediaInterface $media) {
    return $this->mediaArrayBuilder->buildRemoteVideo($media, [$this, 'getImageField']);
  }

  /**
   * Generate data from a video media entity.
   */
  public function generateMediaVideo(MediaInterface $media) {
    return $this->mediaArrayBuilder->buildVideo($media);
  }

  /**
   * Generate data from a document media entity.
   */
  public function generateMediaDocument(MediaInterface $media) {
    return $this->mediaArrayBuilder->buildDocument($media);
  }

  /**
   * Generate data from an SVG media entity.
   */
  public function generateMediaSvg(MediaInterface $media) {
    return $this->mediaArrayBuilder->buildSvg($media);
  }

  /**
   * Generate src from Media Lottie.
   */
  public function generateMediaLottie(MediaInterface $media) {
    return $this->mediaArrayBuilder->buildLottie($media);
  }

  /**
   * Generate src from Media Image.
   */
  public function generateMediaImage(MediaInterface $media, $image_style = '') {
    return $this->mediaArrayBuilder->buildImage($media, $image_style);
  }

  /**
   * Generate src and srcset attributes from image and image style id.
   */
  public function generateFileImage($file, $image_style = '', $params = []) {
    return $this->mediaArrayBuilder->buildFileImage($file, $image_style, $params);
  }

  /**
   * Generate url from Media Image.
   */
  public function generateMediaImageLink($image, $image_style = '') {
    return $this->mediaArrayBuilder->buildImageLink($image, $image_style);
  }

  /**
   * Generate src and srcset attributes from image and image style id.
   */
  public function generateFileImageLink($file, $image_style = '') {
    return $this->mediaArrayBuilder->buildFileImageLink($file, $image_style);
  }

  /**
   * Get image from Media field.
   */
  public function getMediaField($entity, $field_name, $params = []) {

    $items = [];

    $field_name = $this->normalizeFieldName($field_name, $entity);

    if (!$this->validateField($entity, $field_name)) {
      return FALSE;
    }

    $field = $entity->get($field_name);
    if ($field instanceof FieldItemListInterface) {
      foreach ($field as $item) {
        if ($item->entity instanceof MediaInterface) {
          $item = $item->entity;
          $this->cacheMetadata->addCacheableDependency($item);
          // We can translate only published media with this function.
          if ($item->isPublished()) {
            $item = $this->entityRepository->getTranslationFromContext($item);
          }
          $bundle = $item->bundle();
          if ($bundle === 'image') {
            $items[] = $this->generateMediaImage($item);
          }
          elseif ($bundle === 'remote_video') {
            $items[] = $this->generateMediaRemoteVideo($item);
          }
          elseif ($bundle === 'video') {
            $items[] = $this->generateMediaVideo($item);
          }
          elseif ($bundle === 'document') {
            $items[] = $this->generateMediaDocument($item);
          }
          elseif (in_array($bundle, ['svg', 'vector_image', 'svg_image'])) {
            $items[] = $this->generateMediaSvg($item);
          }
          elseif ($bundle === 'lottie') {
            $items[] = $this->generateMediaLottie($item);
          }
        }
      }
    }

    return $this->normalizeReturnValue($items, $params);
  }

  /**
   * Get image from Image field.
   */
  public function getImageField($entity, $field_name, $params = []) {

    $items = [];

    $field_name = $this->normalizeFieldName($field_name, $entity);

    if (!$this->validateField($entity, $field_name)) {
      return FALSE;
    }

    $field = $entity->get($field_name);
    if ($field instanceof FieldItemListInterface) {
      foreach ($field as $item) {
        if (isset($item->entity)) {
          $file = $item->entity;
          if ($file instanceof FileInterface) {
            // Pass alt and title as params; saved per field.
            $item_array = $item->toArray();
            $items[] = $this->generateFileImage(
              $file,
              '',
              $item_array
            );
          }
        }
      }
    }

    return $this->normalizeReturnValue($items, $params);
  }

  /**
   * Get file from File field.
   */
  public function getFileField($entity, $field_name) {

    $langcode = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();

    $items = [];

    $field_name = $this->normalizeFieldName($field_name, $entity);

    if (!$this->validateField($entity, $field_name)) {
      return FALSE;
    }

    $field = $entity->get($field_name);
    if ($field instanceof FieldItemListInterface) {
      foreach ($field as $item) {
        $description = '';
        if (isset($item->description)) {
          $description = $item->description;
        }
        if (isset($item->entity)) {
          $item = $item->entity;
          if ($item instanceof FileInterface) {
            $items[] = [
              'title' => $description ?: $item->getFilename(),
              'filename' => $item->getFilename(),
              'type' => $item->getMimeType(),
              'size' => ByteSizeMarkup::create($item->getSize(), $langcode),
              'url' => $item->createFileUrl(TRUE),
            ];
          }
        }

      }
    }

    return $this->normalizeReturnValue($items);
  }

  /**
   * Get title/url from Link field.
   *
   * @param object $entity
   *   The entity containing the link field.
   * @param string $field_name
   *   The field name (with or without 'field_' prefix).
   * @param array $params
   *   Optional parameters:
   *   - 'output_type': Set to 'url' to return only the URL string(s).
   *   - 'return_format': Set to 'array' to always return an array.
   *
   * @return mixed
   *   Returns FALSE if field doesn't exist, string/array of URL(s) if
   *   output_type is 'url', or array with 'url', 'title', 'attributes' keys.
   */
  public function getLinkField($entity, $field_name, $params = []) {

    $items = [];

    $field_name = $this->normalizeFieldName($field_name, $entity);

    if (!$this->validateField($entity, $field_name)) {
      return FALSE;
    }

    $field = $entity->get($field_name);
    if ($field instanceof FieldItemListInterface) {
      foreach ($field as $item) {
        if ($item instanceof LinkItemInterface) {
          $scheme = parse_url($item->uri, PHP_URL_SCHEME);
          $url = Url::fromUri($item->uri);
          $title = '';
          if (!empty($item->title)) {
            $title = $item->title;
          }
          elseif ($scheme === 'entity') {
            [$entity_type, $entity_id] = explode('/', substr($item->uri, 7), 2);
            if ($entity_type == 'node') {
              $node = $this->entityTypeManager->getStorage('node')->load($entity_id);
              if ($node instanceof NodeInterface) {
                $this->cacheMetadata->addCacheableDependency($node);
                if ($node->isPublished()) {
                  $node = $this->entityRepository->getTranslationFromContext($node);
                  $title = $node->label();
                }
              }
            }
          }
          elseif ($url->isExternal()) {
            $title = str_replace(['http://', 'https://'], '', $url->toString());
          }
          $attributes = [];
          if (isset($item->options['attributes'])) {
            $attributes = $item->options['attributes'];
          }
          // Convert any array attributes to space-separated strings.
          foreach ($attributes as $key => $value) {
            if (is_array($value)) {
              $attributes[$key] = implode(' ', $value);
            }
          }

          if (isset($params['output_type']) && $params['output_type'] === 'url') {
            $items[] = $url->toString();
          }
          else {
            $items[] = [
              'url' => $url->toString(),
              'title' => $title,
              'attributes' => $attributes,
            ];
          }
        }
      }
    }

    return $this->normalizeReturnValue($items, $params);
  }

  /**
   * Get value from Text field.
   */
  public function getTextField($entity, $field_name, $params = []) {

    $items = [];

    $field_name = $this->normalizeFieldName($field_name, $entity);

    if (!$this->validateField($entity, $field_name)) {
      return FALSE;
    }

    $field = $entity->get($field_name);
    if ($field instanceof FieldItemListInterface) {
      foreach ($field as $item) {
        if ($item) {
          $items[] = $item->value;
        }
      }
    }

    return $this->normalizeReturnValue($items, $params, '');
  }

  /**
   * Get value from Double field.
   *
   * @param object $entity
   *   The entity containing the double field.
   * @param string $field_name
   *   The field name (with or without 'field_' prefix).
   * @param array $params
   *   Optional parameters:
   *   - 'return_format': Set to 'array' to always return an array.
   *   - 'keys': Array to rename 'first' and 'second' keys,
   *     e.g. ['first' => 'title', 'second' => 'text'].
   *
   * @return mixed
   *   Returns FALSE if field doesn't exist, or field values
   *   with optional key remapping.
   */
  public function getDoubleField($entity, $field_name, $params = []) {

    $items = [];

    $field_name = $this->normalizeFieldName($field_name, $entity);

    if (!$this->validateField($entity, $field_name)) {
      return FALSE;
    }

    $field = $entity->get($field_name);
    if ($field instanceof FieldItemListInterface) {
      foreach ($field as $item) {
        if ($item) {
          $data = $item->toArray();

          // Rename keys if 'keys' param is provided.
          if (isset($params['keys']) && is_array($params['keys'])) {
            foreach ($params['keys'] as $old_key => $new_key) {
              if (array_key_exists($old_key, $data)) {
                $data[$new_key] = $data[$old_key];
                unset($data[$old_key]);
              }
            }
          }

          $items[] = $data;
        }
      }
    }

    return $this->normalizeReturnValue($items, $params);
  }

  /**
   * Get value from Price field.
   */
  public function getPriceField($entity, $field_name) {

    $value = [];

    $field_name = $this->normalizeFieldName($field_name, $entity);

    if (!$this->validateField($entity, $field_name)) {
      return FALSE;
    }

    $field = $entity->get($field_name);
    if ($field instanceof FieldItemListInterface) {
      foreach ($field as $item) {
        if ($item) {
          $value[] = $item->toPrice();
        }
      }
    }

    return $this->normalizeReturnValue($value);
  }

  /**
   * Get value from Boolean field.
   */
  public function getBooleanField($entity, $field_name) {

    $value = [];

    $field_name = $this->normalizeFieldName($field_name, $entity);

    if (!$this->validateField($entity, $field_name)) {
      return FALSE;
    }

    $field = $entity->get($field_name);
    if ($field instanceof FieldItemListInterface) {
      foreach ($field as $item) {
        if ($item) {
          $value[] = (bool) $item->value;
        }
      }
    }

    return $this->normalizeReturnValue($value, [], FALSE);
  }

  /**
   * Get value from Date field.
   */
  public function getDateField($entity, $field_name) {

    $value = [];

    $field_name = $this->normalizeFieldName($field_name, $entity);

    if (!$this->validateField($entity, $field_name)) {
      return FALSE;
    }

    $field = $entity->get($field_name);
    if ($field instanceof FieldItemListInterface) {
      foreach ($field as $item) {
        if ($item) {
          if ($item->date instanceof DrupalDateTime) {
            $value[] = $item->date->getTimestamp();
          }
          else {
            $value[] = $item->value;
          }
        }
      }
    }

    return $this->normalizeReturnValue($value, [], '');
  }

  /**
   * Get value from Select field.
   */
  public function getSelectField($entity, $field_name) {

    $value = [];

    $field_name = $this->normalizeFieldName($field_name, $entity);

    if (!$this->validateField($entity, $field_name)) {
      return FALSE;
    }

    $field = $entity->get($field_name);
    if ($field instanceof FieldItemListInterface) {
      foreach ($field as $item) {
        if ($item) {
          $value[] = $item->value;
        }
      }
    }

    return $this->normalizeReturnValue($value, [], '');
  }

  /**
   * Get value from Textarea field.
   */
  public function getTextareaField($entity, $field_name) {

    $value = [];

    $exemptions = [
      'body' => NodeInterface::class,
      'description' => TermInterface::class,
    ];
    if (interface_exists(CommentInterface::class)) {
      $exemptions['comment_body'] = CommentInterface::class;
    }
    $field_name = $this->normalizeFieldName($field_name, $entity, $exemptions);

    if (!$this->validateField($entity, $field_name)) {
      return FALSE;
    }

    $field = $entity->get($field_name);
    if ($field instanceof FieldItemListInterface) {
      foreach ($field as $item) {
        if ($item) {
          if ($item->value) {
            $render = [
              '#type' => 'processed_text',
              '#text' => $item->value,
              '#format' => $item->format,
            ];
            $renderer = $this->renderer;
            $output = $renderer->renderInIsolation($render);

            // Bubble cache metadata from text format filters. Filters like
            // media_embed, editor_file_reference, token and linkit add cache
            // tags for referenced entities; renderInIsolation contains the
            // bubbling but the metadata is still attached to $render. Without
            // this, stale WYSIWYG-embedded media/files won't invalidate.
            $this->cacheMetadata->addCacheableDependency(
              CacheableMetadata::createFromRenderArray($render)
            );

            $value[] = $output;
          }
        }
      }
    }

    return $this->normalizeReturnValue($value, [], '');
  }

  /**
   * Get items from Term field.
   */
  public function getTermField($entity, $field_name, $custom_parameters = []) {

    $langcode = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
    $items = [];

    $field_name = $this->normalizeFieldName($field_name, $entity);

    if (!$this->validateField($entity, $field_name)) {
      return FALSE;
    }

    $field = $entity->get($field_name);
    foreach ($field->referencedEntities() as $item) {
      $this->cacheMetadata->addCacheableDependency($item);

      if (isset($custom_parameters['disable_translation']) && $custom_parameters['disable_translation'] === TRUE) {
        $items[] = [
          'id' => $item->id(),
          'title' => $item->label(),
          'url' => $item->toUrl()->toString(),
        ];
      }
      else {
        if ($item->hasTranslation($langcode)) {
          $item = $this->entityRepository->getTranslationFromContext($item);
          $items[] = [
            'id' => $item->id(),
            'title' => $item->label(),
            'url' => $item->toUrl()->toString(),
          ];
        }
      }
    }

    return $items;
  }

  /**
   * Get value from Address field.
   */
  public function getAddressField($entity, $field_name) {

    $langcode = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
    $countries = CountryManager::getStandardList();
    $value = [];

    $field_name = $this->normalizeFieldName($field_name, $entity);

    if (!$this->validateField($entity, $field_name)) {
      return FALSE;
    }

    $field = $entity->get($field_name);
    if ($field instanceof FieldItemListInterface) {
      foreach ($field as $item) {
        if ($item) {
          $address = $item->toArray();
          $country = (string) $countries[$address['country_code']];
          $value[] = [
            'organization' => $address['organization'] ?? '',
            'address' => $address['address_line1'] ?? '',
            'address_second' => $address['address_line2'] ?? '',
            'postal_code' => $address['postal_code'] ?? '',
            'locality' => $address['locality'] ?? '',
            // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
            'country' => (string) $this->t($country, [], ['langcode' => $langcode]),
          ];
        }
      }
    }

    return $this->normalizeReturnValue($value);
  }

  /**
   * Get value from Office Hours field.
   */
  public function getOfficeHoursField($entity, $field_name) {

    // The office_hours module is an optional integration. When it isn't
    // installed, this method has no field type to read from and no formatter
    // available, so bail out early to avoid touching missing classes.
    if (!class_exists(OfficeHoursDateHelper::class)) {
      return FALSE;
    }

    $langcode = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();

    $field_name = $this->normalizeFieldName($field_name, $entity);

    if (!$this->validateField($entity, $field_name)) {
      return FALSE;
    }

    $days = [
      '1' => [
        'title' => $this->t('Mon', [], ['context' => 'Abbreviated weekday', 'langcode' => $langcode]),
        'items' => [],
      ],
      '2' => [
        'title' => $this->t('Tue', [], ['context' => 'Abbreviated weekday', 'langcode' => $langcode]),
        'items' => [],
      ],
      '3' => [
        'title' => $this->t('Wed', [], ['context' => 'Abbreviated weekday', 'langcode' => $langcode]),
        'items' => [],
      ],
      '4' => [
        'title' => $this->t('Thu', [], ['context' => 'Abbreviated weekday', 'langcode' => $langcode]),
        'items' => [],
      ],
      '5' => [
        'title' => $this->t('Fri', [], ['context' => 'Abbreviated weekday', 'langcode' => $langcode]),
        'items' => [],
      ],
      '6' => [
        'title' => $this->t('Sat', [], ['context' => 'Abbreviated weekday', 'langcode' => $langcode]),
        'items' => [],
      ],
      '0' => [
        'title' => $this->t('Sun', [], ['context' => 'Abbreviated weekday', 'langcode' => $langcode]),
        'items' => [],
      ],
    ];

    $field = $entity->get($field_name);
    if ($field instanceof FieldItemListInterface) {
      if (!is_countable($field)) {
        return FALSE;
      }
      foreach ($field as $item) {
        if ($item) {
          $item = $item->toArray();
          $days[$item['day']]['items'][] = [
            'start' => OfficeHoursDateHelper::format($item['starthours'], 'H.i'),
            'end' => OfficeHoursDateHelper::format($item['endhours'], 'H.i'),
            'comment' => $item['comment'],
          ];
        }
      }
    }

    return $days;
  }

  /**
   * Get value from Date Range field.
   */
  public function getDateRangeField($entity, $field_name) {

    $value = [];

    $field_name = $this->normalizeFieldName($field_name, $entity);

    if (!$this->validateField($entity, $field_name)) {
      return FALSE;
    }

    $field = $entity->get($field_name);
    if ($field instanceof FieldItemListInterface) {
      foreach ($field as $item) {
        if (!empty($item->start_date) && !empty($item->end_date)) {
          $start_date = $item->start_date->getTimestamp();
          $end_date = $item->end_date->getTimestamp();
          $formatted = '';
          $date_formatter = $this->dateFormatter;
          if (date('d.m.Y', $start_date) === date('d.m.Y', $end_date)) {
            $formatted = $date_formatter->format($start_date, 'custom', 'd.m.Y')
              . ' ' . $date_formatter->format($start_date, 'custom', 'H:i')
              . ' - ' . $date_formatter->format($end_date, 'custom', 'H:i');
          }
          else {
            $formatted = $date_formatter->format($start_date, 'custom', 'd.m.Y H:i')
              . ' - ' . $date_formatter->format($end_date, 'custom', 'd.m.Y H:i');
          }
          $value[] = [
            'start' => $start_date,
            'end' => $end_date,
            'formatted' => $formatted,
          ];
        }
      }
    }

    return $this->normalizeReturnValue($value, [], []);
  }

  /**
   * Get Webform field.
   */
  public function getWebformField($entity, $field_name) {

    $langcode = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
    $items = [];

    $field_name = $this->normalizeFieldName($field_name, $entity);

    if (!$this->validateField($entity, $field_name)) {
      return FALSE;
    }

    $field = $entity->get($field_name);
    foreach ($field->referencedEntities() as $item) {
      $this->cacheMetadata->addCacheableDependency($item);
      if ($item->hasTranslation($langcode)) {
        $item = $this->entityRepository->getTranslationFromContext($item);
        if ($item) {
          $items[] = $item->getSubmissionForm();
        }
      }
      else {
        $items[] = $item->getSubmissionForm();
      }
    }

    return $this->normalizeReturnValue($items);
  }

  /**
   * Get Menu Field.
   */
  public function getMenuField($entity, $field_name) {

    $value = [];

    $field_name = $this->normalizeFieldName($field_name, $entity);

    if (!$this->validateField($entity, $field_name)) {
      return FALSE;
    }

    $field = $entity->get($field_name);
    if ($field instanceof FieldItemListInterface) {
      foreach ($field as $item) {
        if ($item->entity instanceof MenuInterface) {
          $item = $item->entity;
          $value[] = $this->getMenu($item->id());
        }
      }
    }

    return $this->normalizeReturnValue($value);
  }

  /**
   * Get value from Geo field.
   */
  public function getGeoField($entity, $field_name) {

    $value = [];

    $field_name = $this->normalizeFieldName($field_name, $entity);

    if (!$this->validateField($entity, $field_name)) {
      return FALSE;
    }

    $field = $entity->get($field_name);
    if ($field instanceof FieldItemListInterface) {
      foreach ($field as $item) {
        if ($item) {
          $item = $item->toArray();
          $value[] = [
            'lat' => $item['lat'],
            'lng' => $item['lon'],
          ];
        }
      }
    }

    return $this->normalizeReturnValue($value);
  }

  /**
   * Get Entity Reference field.
   */
  public function getEntityReferenceField($entity, $field_name, $params = []) {

    $items = [];

    $exemptions = [];
    if (interface_exists(ProductInterface::class)) {
      $exemptions['variations'] = ProductInterface::class;
    }
    $field_name = $this->normalizeFieldName($field_name, $entity, $exemptions);

    if (!$this->validateField($entity, $field_name)) {
      return FALSE;
    }

    $field = $entity->get($field_name);
    if ($field instanceof EntityReferenceFieldItemListInterface) {
      foreach ($field->referencedEntities() as $item) {
        $this->cacheMetadata->addCacheableDependency($item);
        if ($item instanceof EntityPublishedInterface) {
          // Check if default language is published.
          if ($item->isPublished()) {
            $item = $this->entityRepository->getTranslationFromContext($item);
            // We need to check if translation is also published.
            if ($item->isPublished()) {
              $items[] = $item;
            }
          }
        }
        elseif ($item instanceof UserInterface) {
          $item = $this->entityRepository->getTranslationFromContext($item);
          $items[] = $item;
        }
      }
    }

    return $this->normalizeReturnValue($items, $params);
  }

  /**
   * Get Menu Links.
   */
  public function getMenu($menu_name, $custom_parameters = []) {
    try {
      $items = $this->menuTreeBuilder->build(
        $menu_name,
        $custom_parameters,
        [$this, 'formatField'],
      );
    }
    finally {
      // Drain even on exception so partial state never leaks into the
      // next getMenu() call on this shared builder.
      $this->cacheMetadata->addCacheableDependency(
        $this->menuTreeBuilder->collectCacheMetadata(),
      );
    }
    return $items;
  }

  /**
   * Get Taxonomy terms.
   *
   * Cache tags from loaded terms and the vocabulary list cache tag are
   * accumulated in $this->cacheMetadata. Retrieve them via
   * collectCacheMetadata() after calling this method.
   *
   * @param string $vocabulary
   *   The vocabulary machine name.
   * @param array $custom_parameters
   *   Optional parameters:
   *   - disable_translation: Skip translation handling.
   *   - nested: TRUE to return hierarchical tree structure with children.
   *
   * @return array
   *   Array of term items, optionally nested with 'children' key.
   */
  public function getTaxonomy($vocabulary, $custom_parameters = []) {
    try {
      $items = $this->taxonomyTreeBuilder->build($vocabulary, $custom_parameters);
    }
    finally {
      // Drain the builder's accumulator even on exception so a partial
      // run does not leak its tags into the next getTaxonomy() call
      // (the builder is a shared service).
      $this->cacheMetadata->addCacheableDependency(
        $this->taxonomyTreeBuilder->collectCacheMetadata(),
      );
    }
    return $items;
  }

  /**
   * Load Select Field Options.
   */
  public function getSelectFieldOptions($langcode, $field_name, $entity_type = 'node') {

    $options = [];

    $field_name = $this->normalizeFieldName($field_name);
    $configName = 'field.storage.' . $entity_type . '.' . $field_name;
    $originalConfig = $this->configFactory->get($configName);
    $translatedConfig = $this->languageManager->getLanguageConfigOverride($langcode, $configName);
    $config = array_replace_recursive($originalConfig->get(), $translatedConfig->get());

    if (isset($config['settings']) && isset($config['settings']['allowed_values'])) {
      foreach ($config['settings']['allowed_values'] as $option) {
        $options[$option['value']] = $option['label'];
      }
    }

    return $options;
  }

  /**
   * Get SVG image dimensions from viewBox.
   */
  public function getSvgViewBoxDimensions($uri) {
    return $this->mediaArrayBuilder->getSvgViewBoxDimensions($uri);
  }

}
