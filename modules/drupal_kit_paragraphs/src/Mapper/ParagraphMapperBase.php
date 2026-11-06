<?php

declare(strict_types=1);

namespace Drupal\drupal_kit_paragraphs\Mapper;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\drupal_kit\Services\EntityHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class of paragraph mapper plugins.
 *
 * It injects the EntityHelper. Field formatting through the helper adds the
 * cacheability of the loaded entities. The mapper drains it after all plugins
 * ran, so a plugin does not handle it.
 */
abstract class ParagraphMapperBase extends PluginBase implements ParagraphMapperInterface, ContainerFactoryPluginInterface {

  /**
   * Constructs a ParagraphMapperBase.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\drupal_kit\Services\EntityHelper $entityHelper
   *   The entity helper.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityHelper $entityHelper,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('drupal_kit.entity_helper'),
    );
  }

}
