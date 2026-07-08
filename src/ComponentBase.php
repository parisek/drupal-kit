<?php

namespace Drupal\drupal_kit;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;

/**
 * Abstract base class for component blocks.
 *
 * Subclasses MUST keep this constructor signature stable so the static
 * ::create() factory's `new static(...)` call succeeds. The annotation
 * below tells PHPStan to trust that contract — but only within this
 * repo's PHPStan scope. Downstream consumers (htdvere, etc.) are NOT
 * checked; a subclass there that adds a constructor param will compile
 * and only fail at runtime when Drupal's plugin manager instantiates
 * it. Pass extra deps through ::create() before forwarding to parent.
 *
 * @phpstan-consistent-constructor
 */
abstract class ComponentBase extends BlockBase implements ContainerFactoryPluginInterface {

  use DependencySerializationTrait;

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
   * The entity helper service.
   *
   * @var \Drupal\drupal_kit\Services\EntityHelper
   */
  protected $entityHelper;

  /**
   * Constructs a ComponentBase object.
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
   * @param \Drupal\drupal_kit\Services\EntityHelper $entity_helper
   *   The entity helper service.
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
    $entity_helper,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->routeMatch = $route_match;
    $this->languageManager = $language_manager;
    $this->entityRepository = $entity_repository;
    $this->configFactory = $config_factory;
    $this->entityHelper = $entity_helper;
    // Do not use loggerFactory as there is bug with
    // media_library_form_element.
    // @see https://www.drupal.org/project/media_library_form_element
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
      $container->get('drupal_kit.entity_helper')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function baseConfigurationDefaults() {
    return parent::baseConfigurationDefaults() + [
      'wrapper_id' => '',
      'wrapper_classes' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $config = $this->getConfiguration();

    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced'),
      '#open' => FALSE,
    ];
    $form['advanced']['wrapper_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Wrapper ID'),
      '#default_value' => $config['wrapper_id'],
      '#description' => $this->t('Unique ID used for anchor links'),
    ];
    $form['advanced']['wrapper_classes'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Wrapper classes'),
      '#default_value' => $config['wrapper_classes'],
      '#description' => $this->t('Multiple classes separated by space. Use TailwindCSS <a href="@url" target="_blank">utility classes</a>.', ['@url' => 'https://tailwindcss.com/docs/utility-first']),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if (!$form_state->getErrors()) {
      $values = $form_state->getValues();
      $this->configuration['wrapper_id'] = $values['advanced']['wrapper_id'];
      $this->configuration['wrapper_classes'] = $values['advanced']['wrapper_classes'];
    }
  }

}
