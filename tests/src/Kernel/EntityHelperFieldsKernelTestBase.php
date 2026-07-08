<?php

namespace Drupal\Tests\drupal_kit\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\drupal_kit\Services\EntityHelper;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Shared base for kernel tests of EntityHelper's per-field-type getters.
 *
 * Each test class extends this and attaches only the field types it
 * needs in its own setUp() — the per-test-type module additions
 * (link, datetime, image, file, taxonomy, …) plug in via $modules.
 *
 * @group drupal_kit
 */
abstract class EntityHelperFieldsKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'drupal_kit',
    'system',
    'user',
    'field',
    'node',
    'text',
    'filter',
  ];

  /**
   * The EntityHelper service (real container).
   */
  protected EntityHelper $entityHelper;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('node_type');
    $this->installSchema('node', ['node_access']);

    NodeType::create([
      'type' => 'test_article',
      'name' => 'Test Article',
    ])->save();

    $this->entityHelper = $this->container->get('drupal_kit.entity_helper');
  }

  /**
   * Attach a field storage + config to the test_article node bundle.
   *
   * @param string $field_name
   *   The field machine name (with or without `field_` prefix).
   * @param string $type
   *   The field type (e.g. 'string', 'datetime', 'link', 'boolean').
   * @param array $storage_settings
   *   Storage-level settings (e.g. ['cardinality' => -1]).
   * @param array $field_settings
   *   Instance-level settings.
   */
  protected function attachField(
    string $field_name,
    string $type,
    array $storage_settings = [],
    array $field_settings = [],
  ): void {
    if (!str_starts_with($field_name, 'field_')) {
      $field_name = 'field_' . $field_name;
    }
    $cardinality = $storage_settings['cardinality'] ?? 1;
    unset($storage_settings['cardinality']);

    FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'node',
      'type' => $type,
      'cardinality' => $cardinality,
      'settings' => $storage_settings,
    ])->save();

    FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'node',
      'bundle' => 'test_article',
      'settings' => $field_settings,
    ])->save();
  }

  /**
   * Create and save a test_article node with the given field values.
   */
  protected function createTestNode(array $field_values = []): Node {
    $node = Node::create([
      'type' => 'test_article',
      'title' => $field_values['title'] ?? 'Test',
    ] + $field_values);
    $node->save();
    return $node;
  }

}
