<?php

namespace Drupal\Tests\custom_components\Kernel\Services;

use Drupal\Tests\custom_components\Kernel\EntityHelperFieldsKernelTestBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Kernel coverage for EntityHelper's mapFields private dispatch +
 * mapping helpers — the largest single coverage gap in #55 Tier 2.
 *
 * Reaches through the public mapFields entry point into the private
 * mapStringConfig / mapArrayConfig / mapDotNotation / buildFieldParams
 * branches that EntityHelperFormatFieldKernelTest's string-mapping
 * tests don't cross.
 *
 * @coversDefaultClass \Drupal\custom_components\Services\EntityHelper
 * @group custom_components
 */
class EntityHelperMapFieldsKernelTest extends EntityHelperFieldsKernelTestBase {

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
    'options',
    'link',
    'taxonomy',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('taxonomy_term');
    $this->installConfig(['filter']);

    $this->attachField('headline', 'string');
    $this->attachField('subtitle', 'string');
    $this->attachField('color', 'list_string', [
      'allowed_values' => ['red' => 'Red', 'blue' => 'Blue'],
    ]);
    $this->attachField('tags', 'entity_reference', [
      'target_type' => 'taxonomy_term',
      'cardinality' => -1,
    ], [
      'handler_settings' => ['target_bundles' => ['topic' => 'topic']],
    ]);

    Vocabulary::create(['vid' => 'topic', 'name' => 'Topic'])->save();
  }

  /**
   * @covers ::mapFields
   * @covers ::mapArrayConfig
   *
   * Array config with a `field` key + extra params — the
   * single-field-with-params branch. Custom params merge ON TOP of
   * buildFieldParams' auto-cardinality default; `return_format: array`
   * forces the result into a list shape even for cardinality-1 fields.
   */
  public function testMapFieldsArrayConfigWithCustomParams(): void {
    $node = $this->createTestNode(['field_headline' => 'Top']);

    $result = $this->entityHelper->mapFields($node, [
      'title' => ['field' => 'headline', 'return_format' => 'array'],
    ]);

    $this->assertIsArray($result['title']);
    $this->assertSame(['Top'], $result['title']);
  }

  /**
   * @covers ::mapFields
   * @covers ::mapArrayConfig
   *
   * Sub-object map pattern — when the output key doesn't resolve to a
   * real field on the entity, every value in the array config is
   * treated as a field name to extract into a nested sub-array.
   */
  public function testMapFieldsArrayConfigSubObjectMap(): void {
    $node = $this->createTestNode([
      'field_headline' => 'Hero',
      'field_subtitle' => 'Tagline',
      'field_color' => 'red',
    ]);

    $result = $this->entityHelper->mapFields($node, [
      // 'hero' is not a field on the entity — values get resolved as
      // field names and the result is a nested array.
      'hero' => [
        'main' => 'headline',
        'sub' => 'subtitle',
        'tone' => 'color',
      ],
    ]);

    $this->assertSame([
      'main' => 'Hero',
      'sub' => 'Tagline',
      'tone' => 'red',
    ], $result['hero']);
  }

  /**
   * @covers ::mapFields
   * @covers ::mapArrayConfig
   *
   * List-array passthrough — when the array config is a numeric list
   * (array_is_list), it's returned verbatim as pre-built data.
   */
  public function testMapFieldsArrayConfigListIsPreBuiltData(): void {
    $node = $this->createTestNode();

    $result = $this->entityHelper->mapFields($node, [
      'static_choices' => ['one', 'two', 'three'],
    ]);

    $this->assertSame(['one', 'two', 'three'], $result['static_choices']);
  }

  /**
   * @covers ::mapFields
   * @covers ::mapArrayConfig
   *
   * Explicit method override — `['method' => 'getTextField', 'field' => …]`
   * bypasses dispatch and calls the named getter directly.
   */
  public function testMapFieldsArrayConfigExplicitMethod(): void {
    $node = $this->createTestNode(['field_headline' => 'Direct']);

    $result = $this->entityHelper->mapFields($node, [
      'label' => ['method' => 'getTextField', 'field' => 'headline'],
    ]);

    $this->assertSame('Direct', $result['label']);
  }

  /**
   * @covers ::mapFields
   *
   * Closure config — when the value is a Closure, it's invoked with
   * the entity and its return value is placed verbatim under the key.
   */
  public function testMapFieldsClosureConfigIsInvokedWithEntity(): void {
    $node = $this->createTestNode(['field_headline' => 'Source']);

    $result = $this->entityHelper->mapFields($node, [
      'derived' => fn($e) => strtoupper((string) $this->entityHelper->formatField($e, 'headline')),
    ]);

    $this->assertSame('SOURCE', $result['derived']);
  }

  /**
   * @covers ::mapFields
   *
   * Scalar (non-string, non-array, non-closure) values pass through
   * unchanged — the catch-all branch in mapFields.
   */
  public function testMapFieldsScalarValuePassesThroughUnchanged(): void {
    $node = $this->createTestNode();

    $result = $this->entityHelper->mapFields($node, [
      'pinned' => 42,
      'enabled' => TRUE,
    ]);

    $this->assertSame(42, $result['pinned']);
    $this->assertTrue($result['enabled']);
  }

  /**
   * @covers ::mapFields
   * @covers ::mapDotNotation
   *
   * Dot notation — `'tags.name'` extracts the `name` field from each
   * referenced entity in `tags` (an entity_reference field targeting
   * taxonomy terms). The helper loads the referenced entities, reads
   * the named field from each, and returns a list under the output
   * key `items`, where each item carries the inner-config key (`label`)
   * mapped to the resolved value.
   */
  public function testMapFieldsDotNotationExtractsChildField(): void {
    $term_a = Term::create(['vid' => 'topic', 'name' => 'Alpha']);
    $term_a->save();
    $term_b = Term::create(['vid' => 'topic', 'name' => 'Beta']);
    $term_b->save();

    $node = $this->createTestNode([
      'field_tags' => [
        ['target_id' => $term_a->id()],
        ['target_id' => $term_b->id()],
      ],
    ]);

    $result = $this->entityHelper->mapFields($node, [
      // 'items' is not a real field on the entity — dot notation reads
      // tags.name (the taxonomy term's `name` field) for each tag.
      'items' => ['label' => 'tags.name'],
    ]);

    $this->assertIsArray($result['items']);
    $this->assertCount(2, $result['items']);
    $labels = array_map(static fn ($i) => $i['label'], $result['items']);
    $this->assertContains('Alpha', $labels);
    $this->assertContains('Beta', $labels);
  }

  /**
   * @covers ::mapFields
   * @covers ::mapStringConfig
   *
   * Unresolved string config — when the named field doesn't exist on
   * the entity, mapStringConfig returns the string as a literal value.
   */
  public function testMapFieldsStringConfigUnresolvedReturnsLiteral(): void {
    $node = $this->createTestNode();

    $result = $this->entityHelper->mapFields($node, [
      'static' => 'no_such_field_name',
    ]);

    $this->assertSame('no_such_field_name', $result['static']);
  }

}
