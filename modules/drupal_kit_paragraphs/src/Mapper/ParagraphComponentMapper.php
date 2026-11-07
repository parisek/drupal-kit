<?php

declare(strict_types=1);

namespace Drupal\drupal_kit_paragraphs\Mapper;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Config\Entity\ThirdPartySettingsInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\drupal_kit\Component\ComponentDefinitionRepositoryInterface;
use Drupal\drupal_kit\Component\InvalidComponentDefinitionException;
use Drupal\drupal_kit\Services\CacheScope;
use Drupal\drupal_kit\Services\EntityHelper;
use Drupal\drupal_kit_paragraphs\Mapping\MappingContext;
use Drupal\drupal_kit_paragraphs\Mapping\ParagraphMapping;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\paragraphs\ParagraphInterface;

/**
 * Maps a paragraph from its component definition.
 *
 * Rules, in order:
 * 1. The framework fields every component accepts: container, heading,
 *    wrapper_id, wrapper_classes, spacing, border.
 * 2. The `fields` tree of the definition. Only `role: field` nodes map. A node
 *    without a role inherits the role of its parent. Roles `query`, `parent`
 *    and `global` do not map.
 *    A colocated `<component>.php` file may return a `replace` step that
 *    builds the data of rule 2 instead. Its data merges over rule 1.
 * 3. The `alter` step of that file, if it has one. The Components registry
 *    already picked the winning folder, so Twig precedence decides the file.
 * 4. The module alter hooks, then the theme alter hooks of the active theme
 *    chain.
 *
 * A missing or malformed definition renders the alert component, like the old
 * hand-written display did, and logs the reason. Other exceptions propagate.
 *
 * The Drupal field of a node is `drupal.field`, or else `field_<key>`. The
 * EntityHelper picks the formatter from the real field definition. The
 * `type`, `multiple` and `of` keys of the YAML only feed diagnostics.
 */
class ParagraphComponentMapper implements ParagraphComponentMapperInterface {

  /**
   * Alter hook that runs for every component.
   */
  public const ALTER_HOOK = 'drupal_kit_paragraph_mapping';

  /**
   * Top-level keys that mapFrameworkFields() sets for every component.
   */
  private const FRAMEWORK_KEYS = ['container', 'heading', 'wrapper_id', 'wrapper_classes', 'spacing', 'border'];

  /**
   * YAML node types and the Drupal field types that fit them.
   *
   * A type that is not listed here is not checked.
   */
  private const TYPE_COMPATIBILITY = [
    'media' => ['entity_reference', 'entity_reference_revisions', 'image', 'file'],
    'link' => ['link'],
    'boolean' => ['boolean'],
    'richtext' => ['text', 'text_long', 'text_with_summary', 'string_long'],
    'select' => ['list_string', 'list_integer', 'list_float', 'entity_reference'],
    'list' => ['entity_reference', 'entity_reference_revisions'],
    'reference' => ['entity_reference', 'entity_reference_revisions', 'webform'],
  ];

  /**
   * Constructs a ParagraphComponentMapper.
   *
   * @param \Drupal\drupal_kit\Component\ComponentDefinitionRepositoryInterface $repository
   *   The component definition repository.
   * @param \Drupal\drupal_kit\Services\EntityHelper $entityHelper
   *   The entity helper.
   * @param \Drupal\drupal_kit_paragraphs\Mapper\ComponentMapperFileLoaderInterface $fileLoader
   *   The loader of colocated component files.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\Theme\ThemeManagerInterface $themeManager
   *   The theme manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container, for MappingContext::service().
   * @param string[] $containerNodeBundles
   *   Parent node bundles whose paragraphs get `container: container`.
   */
  public function __construct(
    protected ComponentDefinitionRepositoryInterface $repository,
    protected EntityHelper $entityHelper,
    protected ComponentMapperFileLoaderInterface $fileLoader,
    protected ModuleHandlerInterface $moduleHandler,
    protected ThemeManagerInterface $themeManager,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected ContainerInterface $container,
    protected array $containerNodeBundles = ['page'],
  ) {}

  /**
   * {@inheritdoc}
   */
  public function map(ParagraphInterface $paragraph, string $viewMode): ParagraphMapping {
    $mapping = new ParagraphMapping();
    $mapping->addCacheableDependency($paragraph);
    // Every exit path depends on the active theme: the component, its PHP
    // file and the fallback alert all differ by theme. Set it before any
    // lookup.
    $mapping->addCacheableDependency((new CacheableMetadata())->setCacheContexts(['theme']));

    // The EntityHelper accumulator is shared by all callers. A scope takes
    // only what this build generates and gives the outer content back
    // untouched, also when an exception passes. On an exception the scope's
    // own metadata is dropped: no mapping is returned, so nothing uses it.
    $scope = $this->entityHelper->beginCacheScope();
    $completed = FALSE;
    try {
      $this->build($mapping, $paragraph, $viewMode, $scope);
      $completed = TRUE;
    }
    finally {
      // Broken component code may leave a scope of its own open. Close those
      // first, so that ours ends and the outer accumulator comes back. When
      // the build completed, its output is used: keep what the leaked scopes
      // collected.
      // When an exception passes, the output is gone: drop it.
      $leaked = $this->entityHelper->abandonCacheScopesAbove($scope, $completed);
      if ($leaked > 0 && $completed) {
        $this->loggerFactory->get('drupal_kit_paragraphs')->warning('The @bundle paragraph mapping left @count cache scope(s) open.', [
          '@bundle' => $paragraph->bundle(),
          '@count' => $leaked,
        ]);
      }
      $collected = $this->entityHelper->endCacheScope($scope);
    }

    $mapping->addCacheableDependency($collected);
    return $mapping;
  }

  /**
   * Builds the mapping.
   *
   * Two problems are recoverable and render the alert component, as the old
   * hand-written display did: no definition, and a malformed definition. Any
   * other exception is a programming error and propagates.
   */
  protected function build(ParagraphMapping $mapping, ParagraphInterface $paragraph, string $viewMode, CacheScope $scope): void {
    $logger = $this->loggerFactory->get('drupal_kit_paragraphs');

    try {
      $definition = $this->repository->loadForBundle($paragraph->bundle());
      $file = $definition === NULL ? NULL : $this->fileLoader->load($definition);
      $query_fields = $definition === NULL ? [] : $this->findQueryFields($definition->getFields());
      // Read fields only when something will render. An alert, and a replace
      // step that builds everything, need no generic rules, and the reads
      // would only spend cache tags.
      $generic = $definition === NULL || ($query_fields !== [] && $file === NULL) || $file?->getReplace() !== NULL
        ? NULL
        : $this->mapNodes($paragraph, $definition->getFields(), NULL, $mapping, '');
    }
    catch (InvalidComponentDefinitionException $e) {
      $logger->error('The component definition or file of the @bundle paragraph bundle is malformed: @message', [
        '@bundle' => $paragraph->bundle(),
        '@message' => $e->getMessage(),
      ]);
      $this->mapAlert($mapping, $paragraph);
      return;
    }

    if ($definition === NULL) {
      $logger->warning('No component definition for the @bundle paragraph bundle.', [
        '@bundle' => $paragraph->bundle(),
      ]);
      $this->mapAlert($mapping, $paragraph);
      return;
    }

    $component = $definition->getName();
    if ($query_fields !== [] && $file === NULL) {
      // The data of these fields comes from PHP (queries, views). Without a
      // colocated PHP step the component would render with the data missing
      // and nobody would see it.
      $note = sprintf('The %s component has query fields (%s) and no %s%s file.', $component, implode(', ', $query_fields), $component, ComponentMapperFileLoader::SUFFIX);
      $mapping->addDiagnostic($note);
      $logger->warning('The @component component needs a PHP file for its query fields (@fields), and @file is missing.', [
        '@component' => $component,
        '@fields' => implode(', ', $query_fields),
        '@file' => $component . ComponentMapperFileLoader::SUFFIX,
      ]);
      $this->mapAlert($mapping, $paragraph, new TranslatableMarkup('Drupal implementation for component <strong>@template</strong> needs a PHP mapper file', [
        '@template' => Html::cleanCssIdentifier($paragraph->bundle()),
      ]));
      return;
    }

    $mapping->setComponent($component)->setTemplate($component);
    $context = new MappingContext($paragraph, $viewMode, $definition, $this->container);
    $this->mapFrameworkFields($mapping, $paragraph);
    $replace = $file?->getReplace();
    if ($replace !== NULL) {
      $replaced = $replace($paragraph, $viewMode, $this->entityHelper, $context);
      if (!$replaced instanceof ParagraphMapping) {
        throw new \UnexpectedValueException(sprintf('The "replace" step in %s must return a ParagraphMapping.', $file->getPath()));
      }
      $this->closeLeakedScopes($scope, 'the replace step in ' . $file->getPath());
      $this->adopt($mapping, $replaced);
    }
    else {
      foreach ($generic ?? [] as $key => $value) {
        $this->merge($mapping, $key, $value);
      }
    }

    $alter = $file?->getAlter();
    if ($alter !== NULL) {
      $alter($mapping, $context, $this->entityHelper);
      $this->closeLeakedScopes($scope, 'the alter step in ' . $file->getPath());
    }

    // Modules first, then the active theme and its base themes.
    $types = [self::ALTER_HOOK, self::ALTER_HOOK . '_' . str_replace('-', '_', $component)];
    $this->moduleHandler->alter($types, $mapping, $context);
    $this->closeLeakedScopes($scope, 'a module alter hook');
    $this->themeManager->alter($types, $mapping, $context);
    $this->closeLeakedScopes($scope, 'a theme alter hook');
  }

  /**
   * Takes the result of a `replace` step into our mapping.
   *
   * The data merges over the framework fields, so container, heading,
   * wrapper_id, wrapper_classes, spacing and border stay unless the step sets
   * them. Template, component, cacheability and notes move over. The mapping
   * the caller gets stays our object, so the cache scope bookkeeping holds.
   */
  private function adopt(ParagraphMapping $mapping, ParagraphMapping $replaced): void {
    foreach ($replaced->getData() as $key => $value) {
      $this->merge($mapping, $key, $value);
    }
    if ($replaced->getComponent() !== NULL) {
      $mapping->setComponent($replaced->getComponent());
    }
    $template = $replaced->getTemplate();
    if ($template !== NULL) {
      $mapping->setTemplate($template);
    }
    $mapping->addCacheableDependency($replaced->getCacheableMetadata());
    foreach ($replaced->getDiagnostics() as $note) {
      $mapping->addDiagnostic($note);
    }
  }

  /**
   * Finds the fields whose data PHP supplies: `role: query` outside framework.
   *
   * The framework fields (container, heading, wrapper_id, wrapper_classes,
   * spacing, border) are set by this mapper whatever the role says, so they do
   * not count. Role `parent` does not count either: a caller supplies those
   * values, and a component such as `content` is complete without them.
   *
   * @param array<string, mixed> $nodes
   *   The definition nodes.
   * @param string $path
   *   The dotted path of the parent, for the result.
   *
   * @return string[]
   *   The dotted paths of the query fields.
   */
  private function findQueryFields(array $nodes, string $path = ''): array {
    $found = [];
    foreach ($nodes as $key => $node) {
      if (!is_string($key) || !is_array($node)) {
        continue;
      }
      if ($path === '' && in_array($key, self::FRAMEWORK_KEYS, TRUE)) {
        continue;
      }
      $here = ltrim($path . '.' . $key, '.');
      if (($node['role'] ?? NULL) === 'query') {
        $found[] = $here;
      }
      elseif (is_array($node['fields'] ?? NULL)) {
        $found = array_merge($found, $this->findQueryFields($node['fields'], $here));
      }
    }
    return $found;
  }

  /**
   * Closes a scope that a component file or hook left open.
   *
   * Its metadata is kept.
   *
   * The output is used, so the leaked scope's tags, contexts and max-age merge
   * into ours. The warning names the source.
   *
   * @param \Drupal\drupal_kit\Services\CacheScope $scope
   *   Our scope.
   * @param string $source
   *   Who ran: a component file step or a hook kind.
   */
  private function closeLeakedScopes(CacheScope $scope, string $source): void {
    $leaked = $this->entityHelper->abandonCacheScopesAbove($scope, TRUE);
    if ($leaked > 0) {
      $this->loggerFactory->get('drupal_kit_paragraphs')->warning('@source left @count cache scope(s) open. Its cache metadata was kept.', [
        '@source' => $source,
        '@count' => $leaked,
      ]);
    }
  }

  /**
   * Turns the mapping into the "implementation is missing" alert.
   *
   * The data is what the old display built for a bundle it did not know: the
   * framework fields, then an error alert in a container.
   *
   * @param \Drupal\drupal_kit_paragraphs\Mapping\ParagraphMapping $mapping
   *   The mapping to turn into the alert.
   * @param \Drupal\paragraphs\ParagraphInterface $paragraph
   *   The paragraph.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $message
   *   The message. It defaults to "implementation is missing".
   */
  protected function mapAlert(ParagraphMapping $mapping, ParagraphInterface $paragraph, ?TranslatableMarkup $message = NULL): void {
    $mapping->replaceData([]);
    $this->mapFrameworkFields($mapping, $paragraph);
    $mapping->set('type', 'error');
    $mapping->set('container', 'container');
    $mapping->set('message', $message ?? new TranslatableMarkup('Drupal implementation for component <strong>@template</strong> is missing', [
      '@template' => Html::cleanCssIdentifier($paragraph->bundle()),
    ]));
    $mapping->setComponent('alert')->setTemplate('alert');
  }

  /**
   * Sets the framework fields that every component accepts.
   */
  protected function mapFrameworkFields(ParagraphMapping $mapping, ParagraphInterface $paragraph): void {
    $parent = $paragraph->getParentEntity();
    $container = '';
    if ($parent instanceof NodeInterface && in_array($parent->bundle(), $this->containerNodeBundles, TRUE)) {
      $container = 'container';
    }
    $mapping->set('container', $container);

    $title = $this->entityHelper->getTextField($paragraph, 'title');
    if (!empty($title)) {
      $heading = $mapping->get('heading', []);
      $heading['title'] = $title;
      $mapping->set('heading', $heading);
    }
    $perex = $this->entityHelper->getTextareaField($paragraph, 'perex');
    if (!empty($perex)) {
      $heading = $mapping->get('heading', []);
      $heading['perex'] = $perex;
      $mapping->set('heading', $heading);
    }
    $shared = [
      'wrapper_id' => 'getTextField',
      'wrapper_classes' => 'getTextField',
      'spacing' => 'getSelectField',
      'border' => 'getSelectField',
    ];
    foreach ($shared as $key => $method) {
      $value = $this->entityHelper->{$method}($paragraph, $key);
      if (!empty($value)) {
        $mapping->set($key, $value);
      }
    }
  }

  /**
   * Maps a tree of definition nodes against one entity.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to read from.
   * @param array<string, mixed> $nodes
   *   The definition nodes.
   * @param string|null $inheritedRole
   *   The role of the parent node.
   * @param \Drupal\drupal_kit_paragraphs\Mapping\ParagraphMapping $mapping
   *   The mapping that collects diagnostics.
   * @param string $path
   *   The dotted path of the parent, for diagnostics.
   *
   * @return array<string, mixed>
   *   The mapped values, keyed like the definition.
   */
  protected function mapNodes(FieldableEntityInterface $entity, array $nodes, ?string $inheritedRole, ParagraphMapping $mapping, string $path): array {
    $result = [];
    foreach ($nodes as $key => $node) {
      $here = ltrim($path . '.' . $key, '.');
      if (!is_string($key) || !is_array($node)) {
        throw new InvalidComponentDefinitionException("The field node $here must be a map with a text key.");
      }

      $role = $node['role'] ?? $inheritedRole;
      if ($role !== 'field') {
        continue;
      }

      $children = $node['fields'] ?? NULL;
      $explicit = $node['drupal']['field'] ?? NULL;
      $type = $node['type'] ?? NULL;

      // A group of keys that no Drupal field backs, for example `heading`.
      if (is_array($children) && in_array($type, ['object', 'group'], TRUE) && $explicit === NULL) {
        $group = $this->mapNodes($entity, $children, $role, $mapping, $here);
        if ($group !== []) {
          $result[$key] = $group;
        }
        continue;
      }

      $field_name = $this->resolveFieldName($entity, $key, $explicit);
      if ($field_name === NULL) {
        $mapping->addDiagnostic("$here: entity has no field " . ($explicit ?? "field_$key") . '.');
        continue;
      }
      $field_definition = $entity->getFieldDefinition($field_name);
      $this->checkShape($mapping, $here, $node, $field_definition);

      if (is_array($children)) {
        $items = $this->mapChildren($entity, $field_name, $children, $role, $mapping, $here, $field_definition->getType());
        if ($items !== []) {
          $result[$key] = $items;
        }
        continue;
      }

      // mapFields() returns a list for a field that holds many values and the
      // bare value for one. A definition that says `multiple: false` on a field
      // that allows many values wants the bare value, so it goes through
      // formatField() with no list option. The definition never forces a list
      // on a one-value field: the formatter already returns one.
      if (($node['multiple'] ?? NULL) === FALSE && $this->effectiveCardinality($field_definition) !== 1) {
        $result[$key] = $this->entityHelper->formatField($entity, $field_name, []);
        continue;
      }
      $mapped = $this->entityHelper->mapFields($entity, [$key => ['field' => $field_name]]);
      $result[$key] = $mapped[$key] ?? NULL;
    }
    return $result;
  }

  /**
   * Maps the referenced entities of a list node.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The parent entity.
   * @param string $field_name
   *   The reference field.
   * @param array<string, mixed> $children
   *   The child definition nodes.
   * @param string|null $role
   *   The role of the list node.
   * @param \Drupal\drupal_kit_paragraphs\Mapping\ParagraphMapping $mapping
   *   The mapping that collects diagnostics.
   * @param string $path
   *   The dotted path of the list node, for diagnostics.
   * @param string $field_type
   *   The Drupal type of the reference field.
   *
   * @return array<int, array<string, mixed>>
   *   One mapped array per published child.
   */
  protected function mapChildren(FieldableEntityInterface $entity, string $field_name, array $children, ?string $role, ParagraphMapping $mapping, string $path, string $field_type): array {
    if (!in_array($field_type, ['entity_reference', 'entity_reference_revisions'], TRUE)) {
      $mapping->addDiagnostic("$path: has child fields, but $field_name is a $field_type field and no reference.");
      return [];
    }
    $referenced = $this->entityHelper->getEntityReferenceField($entity, $field_name, ['return_format' => 'array']);
    if (!is_array($referenced)) {
      return [];
    }
    $items = [];
    foreach ($referenced as $child) {
      if ($child instanceof FieldableEntityInterface) {
        $items[] = $this->mapNodes($child, $children, $role, $mapping, $path);
      }
    }
    return $items;
  }

  /**
   * Resolves the Drupal field name of a node.
   *
   * `drupal.field` wins. Without it, the name is `field_<key>`.
   */
  protected function resolveFieldName(FieldableEntityInterface $entity, string $key, mixed $explicit): ?string {
    $name = is_string($explicit) && $explicit !== '' ? $explicit : 'field_' . $key;
    return $entity->hasField($name) ? $name : NULL;
  }

  /**
   * Gets the cardinality EntityHelper uses to choose between value and list.
   *
   * The per-instance override wins over the storage.
   */
  protected function effectiveCardinality(FieldDefinitionInterface $field_definition): int {
    $override = $field_definition instanceof ThirdPartySettingsInterface
      ? $field_definition->getThirdPartySetting('field_config_cardinality', 'cardinality_config')
      : NULL;
    return $override !== NULL ? (int) $override : $field_definition->getFieldStorageDefinition()->getCardinality();
  }

  /**
   * Compares the YAML shape with the real field, for diagnostics only.
   *
   * @param \Drupal\drupal_kit_paragraphs\Mapping\ParagraphMapping $mapping
   *   The mapping that collects diagnostics.
   * @param string $path
   *   The dotted path of the node.
   * @param array<string, mixed> $node
   *   The definition node.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The real field definition.
   */
  protected function checkShape(ParagraphMapping $mapping, string $path, array $node, FieldDefinitionInterface $field_definition): void {
    $drupal_type = $field_definition->getType();
    $type = $node['type'] ?? NULL;
    if (is_string($type) && isset(self::TYPE_COMPATIBILITY[$type]) && !in_array($drupal_type, self::TYPE_COMPATIBILITY[$type], TRUE)) {
      $mapping->addDiagnostic("$path: YAML type '$type' does not fit the $drupal_type field.");
    }

    $cardinality = $this->effectiveCardinality($field_definition);
    $multiple = !empty($node['multiple']) || ($node['type'] ?? NULL) === 'list';
    if ($multiple && $cardinality === 1) {
      $mapping->addDiagnostic("$path: YAML says multiple, the field holds one value.");
    }
    elseif (!$multiple && $cardinality !== 1) {
      $mapping->addDiagnostic("$path: YAML says single, the field allows $cardinality values.");
    }

    if (isset($node['of']) && is_string($node['of']) && str_starts_with($node['of'], 'entity:')) {
      $target = substr($node['of'], strlen('entity:'));
      $field_target = $field_definition->getFieldStorageDefinition()->getSetting('target_type');
      if ($field_target !== NULL && $field_target !== $target) {
        $mapping->addDiagnostic("$path: YAML 'of' says $target, the field references $field_target.");
      }
    }
  }

  /**
   * Merges one top-level value into the mapping.
   *
   * Two maps merge key by key. Anything else is replaced.
   */
  private function merge(ParagraphMapping $mapping, string $key, mixed $value): void {
    $existing = $mapping->get($key);
    if (is_array($existing) && is_array($value) && !array_is_list($existing) && !array_is_list($value)) {
      $value = array_replace($existing, $value);
    }
    $mapping->set($key, $value);
  }

}
