<?php

namespace Drupal\drupal_kit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Transliteration\TransliterationInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\extra_field\Plugin\ExtraFieldDisplayBase;

/**
 * Abstract base class for display blocks.
 *
 * All EntityHelper methods are available via __call() delegation.
 *
 * @method mixed getMediaField($entity, $field_name, $params = [])
 * @method mixed getImageField($entity, $field_name, $params = [])
 * @method mixed getFileField($entity, $field_name)
 * @method mixed getLinkField($entity, $field_name, $params = [])
 * @method mixed getTextField($entity, $field_name, $params = [])
 * @method mixed getDoubleField($entity, $field_name, $params = [])
 * @method mixed getPriceField($entity, $field_name)
 * @method mixed getBooleanField($entity, $field_name)
 * @method mixed getDateField($entity, $field_name)
 * @method mixed getSelectField($entity, $field_name)
 * @method mixed getTextareaField($entity, $field_name)
 * @method mixed getEmailField($entity, $field_name, $params = [])
 * @method mixed getPhoneField($entity, $field_name, $params = [])
 * @method mixed getTermField($entity, $field_name, $custom_parameters = [])
 * @method mixed getAddressField($entity, $field_name)
 * @method mixed getOfficeHoursField($entity, $field_name)
 * @method mixed getDateRangeField($entity, $field_name)
 * @method mixed getWebformField($entity, $field_name)
 * @method mixed getMenuField($entity, $field_name)
 * @method mixed getGeoField($entity, $field_name)
 * @method mixed getEntityReferenceField($entity, $field_name, $params = [])
 * @method mixed formatField($entity, $field_name, $params = [])
 * @method array formatFields($entity, $params = [])
 * @method array mapFields($entity, array $map = [])
 * @method mixed getMenu($menu_name, $custom_parameters = [])
 * @method mixed getTaxonomy($vocabulary, $custom_parameters = [])
 * @method mixed getSelectFieldOptions($langcode, $field_name, $entity_type = 'node')
 * @method mixed getSvgViewBoxDimensions($uri)
 *
 * Subclasses MUST keep the constructor signature stable so ::create()'s
 * `new static(...)` succeeds. Documentation-only contract — see the
 * note on ComponentBase for details.
 *
 * @phpstan-consistent-constructor
 */
abstract class DisplayBase extends ExtraFieldDisplayBase implements ContainerFactoryPluginInterface {

  use DependencySerializationTrait;
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
   * The entity helper service.
   *
   * @var \Drupal\drupal_kit\Services\EntityHelper
   */
  protected $entityHelper;

  /**
   * The path matcher service.
   *
   * @var \Drupal\Core\Path\PathMatcherInterface
   */
  protected $pathMatcher;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The transliteration service.
   *
   * @var \Drupal\Component\Transliteration\TransliterationInterface
   */
  protected $transliteration;

  /**
   * Constructs a DisplayBase object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match service.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager service.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection service.
   * @param \Drupal\drupal_kit\Services\EntityHelper $entity_helper
   *   The entity helper service.
   * @param \Drupal\Core\Path\PathMatcherInterface $path_matcher
   *   The path matcher service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Component\Transliteration\TransliterationInterface $transliteration
   *   The transliteration service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    RouteMatchInterface $route_match,
    LanguageManagerInterface $language_manager,
    EntityRepositoryInterface $entity_repository,
    ConfigFactoryInterface $config_factory,
    Connection $connection,
    $entity_helper,
    PathMatcherInterface $path_matcher,
    RequestStack $request_stack,
    TransliterationInterface $transliteration,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->routeMatch = $route_match;
    $this->languageManager = $language_manager;
    $this->entityRepository = $entity_repository;
    $this->configFactory = $config_factory;
    $this->connection = $connection;
    $this->entityHelper = $entity_helper;
    $this->pathMatcher = $path_matcher;
    $this->requestStack = $request_stack;
    $this->transliteration = $transliteration;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('current_route_match'),
      $container->get('language_manager'),
      $container->get('entity.repository'),
      $container->get('config.factory'),
      $container->get('database'),
      $container->get('drupal_kit.entity_helper'),
      $container->get('path.matcher'),
      $container->get('request_stack'),
      $container->get('transliteration'),
    );
  }

  /**
   * Aliases that map to a different EntityHelper method.
   *
   * @var array<string, string>
   */
  protected static array $methodAliases = [
    'getEmailField' => 'getTextField',
    'getPhoneField' => 'getTextField',
  ];

  /**
   * Delegates method calls to the EntityHelper service.
   *
   * @param string $name
   *   The method name.
   * @param array $arguments
   *   The method arguments.
   *
   * @return mixed
   *   The return value from EntityHelper.
   *
   * @throws \BadMethodCallException
   *   When the method does not exist on EntityHelper.
   */
  public function __call(string $name, array $arguments): mixed {
    $target = static::$methodAliases[$name] ?? $name;

    if (method_exists($this->entityHelper, $target)) {
      return $this->entityHelper->{$target}(...$arguments);
    }

    throw new \BadMethodCallException(sprintf('Method %s::%s() does not exist.', static::class, $name));
  }

}
