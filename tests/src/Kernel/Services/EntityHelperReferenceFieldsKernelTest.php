<?php

namespace Drupal\Tests\custom_components\Kernel\Services;

use Drupal\Tests\custom_components\Kernel\EntityHelperFieldsKernelTestBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * @coversDefaultClass \Drupal\custom_components\Services\EntityHelper
 * @group custom_components
 */
class EntityHelperReferenceFieldsKernelTest extends EntityHelperFieldsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'custom_components',
    'system',
    'user',
    'field',
    'node',
    'text',
    'filter',
    'taxonomy',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('taxonomy_term');

    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();

    $this->attachField('topics', 'entity_reference', [
      'target_type' => 'taxonomy_term',
    ], [
      'handler' => 'default:taxonomy_term',
      'handler_settings' => ['target_bundles' => ['tags' => 'tags']],
    ]);
  }

  /**
   * @covers ::getTermField
   */
  public function testGetTermFieldReturnsLabels(): void {
    $alpha = Term::create(['vid' => 'tags', 'name' => 'Alpha']);
    $alpha->save();
    $beta = Term::create(['vid' => 'tags', 'name' => 'Beta']);
    $beta->save();

    $node = $this->createTestNode([
      'field_topics' => [
        ['target_id' => $alpha->id()],
        ['target_id' => $beta->id()],
      ],
    ]);

    $value = $this->entityHelper->getTermField($node, 'topics');
    $serialized = is_array($value) ? json_encode($value) : (string) $value;

    $this->assertStringContainsString('Alpha', $serialized);
    $this->assertStringContainsString('Beta', $serialized);
  }

  /**
   * @covers ::getTermField
   */
  public function testGetTermFieldEmptyReturnsEmpty(): void {
    $node = $this->createTestNode();
    $value = $this->entityHelper->getTermField($node, 'topics');
    $this->assertTrue($value === [] || $value === '');
  }

  /**
   * @covers ::getEntityReferenceField
   */
  public function testGetEntityReferenceFieldReturnsReferencedEntityData(): void {
    $term = Term::create(['vid' => 'tags', 'name' => 'Gamma']);
    $term->save();

    $node = $this->createTestNode([
      'field_topics' => [['target_id' => $term->id()]],
    ]);

    $value = $this->entityHelper->getEntityReferenceField($node, 'topics');
    $serialized = is_array($value) ? json_encode($value) : (string) $value;
    $this->assertStringContainsString('Gamma', $serialized);
  }

}
