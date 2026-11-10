<?php

declare(strict_types=1);

namespace Drupal\drupal_kit_paragraphs\Mapping;

use Drupal\drupal_kit\Component\ComponentDefinition;
use Drupal\drupal_kit\Services\EntityHelper;
use Drupal\paragraphs\ParagraphInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Read-only input of one mapping run.
 *
 * Component files and alter hooks get the context next to the mapping. They
 * read the paragraph, the view mode and the definition here. They write only
 * to the mapping.
 *
 * @experimental
 *   The API may change before the module has a stable release.
 */
final class MappingContext {

  /**
   * Constructs a MappingContext.
   */
  public function __construct(
    private readonly ParagraphInterface $paragraph,
    private readonly string $viewMode,
    private readonly ComponentDefinition $definition,
    private readonly ?ContainerInterface $container = NULL,
    private readonly ?EntityHelper $helper = NULL,
  ) {}

  /**
   * The entities and access results that loadVisible() recorded.
   *
   * @var \Drupal\Core\Cache\CacheableDependencyInterface[]
   */
  private array $loaded = [];

  /**
   * Gets any service, for a colocated component file that needs one.
   *
   * @param string $id
   *   The service id.
   *
   * @return object
   *   The service.
   *
   * @throws \LogicException
   *   When the context was built without a container.
   */
  public function service(string $id): object {
    if ($this->container === NULL) {
      throw new \LogicException('This mapping context has no service container.');
    }
    return $this->container->get($id);
  }

  /**
   * Gets the entity helper, for a field the shortcuts below do not cover.
   *
   * @throws \LogicException
   *   When the context was built without a helper.
   */
  public function fields(): EntityHelper {
    return $this->helper ?? throw new \LogicException('This mapping context has no entity helper.');
  }

  /**
   * Gets a media field of the paragraph.
   *
   * @param string $field
   *   The field name.
   * @param array<string, mixed> $params
   *   The formatter parameters.
   *
   * @return mixed
   *   What EntityHelper::getMediaField() returns.
   */
  public function media(string $field, array $params = []): mixed {
    return $this->fields()->getMediaField($this->paragraph, $field, $params);
  }

  /**
   * Gets a text field of the paragraph.
   */
  public function text(string $field, array $params = []): mixed {
    return $this->fields()->getTextField($this->paragraph, $field, $params);
  }

  /**
   * Gets a plain text area field of the paragraph.
   */
  public function textarea(string $field): mixed {
    return $this->fields()->getTextareaField($this->paragraph, $field);
  }

  /**
   * Gets a link field of the paragraph.
   */
  public function link(string $field, array $params = []): mixed {
    return $this->fields()->getLinkField($this->paragraph, $field, $params);
  }

  /**
   * Gets a taxonomy term field of the paragraph.
   */
  public function terms(string $field, array $params = []): mixed {
    return $this->fields()->getTermField($this->paragraph, $field, $params);
  }

  /**
   * Gets a boolean field of the paragraph.
   */
  public function boolean(string $field): mixed {
    return $this->fields()->getBooleanField($this->paragraph, $field);
  }

  /**
   * Gets the language code of the content that the page shows.
   */
  public function contentLangcode(): string {
    return $this->service('language_manager')->getCurrentLanguage('language_content')->getId();
  }

  /**
   * Loads entities for a list, in the order of the ids.
   *
   * Each entity is the translation that fits the current content language. An
   * entity that the current user may not view is left out. A raw database
   * query ignores access, so every list that starts from one needs this check.
   *
   * The mapper adds every loaded entity, also a hidden one, and the cache
   * metadata of each access answer as cache dependencies of the mapping. A
   * component file does not do that by hand. It adds the list tag with
   * ParagraphMapping::addListDependency().
   *
   * @param string $entity_type
   *   The entity type id, for example "node".
   * @param array<int|string, int|string> $ids
   *   The ids, in the order the list shows them.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   The visible translations, keyed by id, in the order of $ids.
   */
  public function loadVisible(string $entity_type, array $ids): array {
    if ($ids === []) {
      return [];
    }
    $loaded = $this->service('entity_type.manager')->getStorage($entity_type)->loadMultiple($ids);
    $repository = $this->service('entity.repository');
    $visible = [];
    foreach ($ids as $id) {
      if (!isset($loaded[$id])) {
        continue;
      }
      $entity = $repository->getTranslationFromContext($loaded[$id]);
      // The access result carries the contexts and tags that the answer
      // depends on (permissions, node grants, the published state). A plain
      // boolean would drop them and let the list be cached for the wrong user.
      $access = $entity->access('view', NULL, TRUE);
      $this->loaded[] = $entity;
      $this->loaded[] = $access;
      if ($access->isAllowed()) {
        $visible[$id] = $entity;
      }
    }
    return $visible;
  }

  /**
   * Hands the entities and access results recorded so far to the mapper.
   *
   * @return \Drupal\Core\Cache\CacheableDependencyInterface[]
   *   What loadVisible() recorded since the last call.
   *
   * @internal
   */
  public function takeLoaded(): array {
    $loaded = $this->loaded;
    $this->loaded = [];
    return $loaded;
  }

  /**
   * Gets the paragraph that is mapped.
   */
  public function getParagraph(): ParagraphInterface {
    return $this->paragraph;
  }

  /**
   * Gets the view mode.
   */
  public function getViewMode(): string {
    return $this->viewMode;
  }

  /**
   * Gets the parsed component definition.
   */
  public function getDefinition(): ComponentDefinition {
    return $this->definition;
  }

  /**
   * Gets the component name.
   */
  public function getComponent(): string {
    return $this->definition->getName();
  }

}
