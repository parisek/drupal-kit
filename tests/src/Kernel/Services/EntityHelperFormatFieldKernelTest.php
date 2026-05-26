<?php

namespace Drupal\Tests\custom_components\Kernel\Services;

use Drupal\Tests\custom_components\Kernel\EntityHelperFieldsKernelTestBase;

/**
 * Behavioral tests for EntityHelper::formatField polymorphic dispatch.
 *
 * Exercises every field-type branch end-to-end against a real
 * test_article node with the corresponding field type attached. This
 * is the kernel-side companion to the mocked dispatch tests in
 * EntityHelperTest::testFormatField* — those verify mock-call shape;
 * these verify the real getter is reached for each declared type.
 *
 * @coversDefaultClass \Drupal\custom_components\Services\EntityHelper
 * @group custom_components
 */
class EntityHelperFormatFieldKernelTest extends EntityHelperFieldsKernelTestBase {

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
    'datetime',
    'datetime_range',
    'link',
    'taxonomy',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['filter']);
    $this->installEntitySchema('taxonomy_term');

    $this->attachField('headline', 'string');
    $this->attachField('body_content', 'text_long');
    $this->attachField('published_at', 'datetime', [], ['datetime_type' => 'datetime']);
    $this->attachField('event_span', 'daterange', [], ['datetime_type' => 'datetime']);
    $this->attachField('cta', 'link', [], ['title' => 1, 'link_type' => 17]);
    $this->attachField('color', 'list_string', [
      'allowed_values' => ['red' => 'Red', 'blue' => 'Blue'],
    ]);
    $this->attachField('flag', 'boolean');
    $this->attachField('rating', 'float');

    \Drupal\taxonomy\Entity\Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    $this->attachField('tags', 'entity_reference', [
      'target_type' => 'taxonomy_term',
    ], [
      'handler' => 'default:taxonomy_term',
      'handler_settings' => ['target_bundles' => ['tags' => 'tags']],
    ]);
  }

  /**
   * @covers ::formatField
   */
  public function testDispatchString(): void {
    $node = $this->createTestNode(['field_headline' => 'Hello']);
    $this->assertSame('Hello', $this->entityHelper->formatField($node, 'headline'));
  }

  /**
   * @covers ::formatField
   */
  public function testDispatchTextLong(): void {
    $node = $this->createTestNode(['field_body_content' => [
      'value' => 'Body text',
      'format' => 'plain_text',
    ]]);
    $result = $this->entityHelper->formatField($node, 'body_content');
    $this->assertStringContainsString('Body text', (string) $result);
  }

  /**
   * @covers ::formatField
   */
  public function testDispatchDatetime(): void {
    $node = $this->createTestNode(['field_published_at' => '2024-03-14T10:00:00']);
    $result = $this->entityHelper->formatField($node, 'published_at');
    $ts = is_array($result) ? (int) reset($result) : (int) $result;
    $this->assertGreaterThan(0, $ts);
  }

  /**
   * @covers ::formatField
   */
  public function testDispatchDaterange(): void {
    $node = $this->createTestNode(['field_event_span' => [
      'value' => '2024-03-14T10:00:00',
      'end_value' => '2024-03-14T18:00:00',
    ]]);
    $result = $this->entityHelper->formatField($node, 'event_span');
    $this->assertNotNull($result);
  }

  /**
   * @covers ::formatField
   */
  public function testDispatchLink(): void {
    $node = $this->createTestNode(['field_cta' => [
      'uri' => 'https://example.com/',
      'title' => 'Click',
    ]]);
    $result = $this->entityHelper->formatField($node, 'cta');
    $serialized = is_array($result) ? json_encode($result) : (string) $result;
    $this->assertStringContainsString('Click', $serialized);
  }

  /**
   * @covers ::formatField
   */
  public function testDispatchListString(): void {
    $node = $this->createTestNode(['field_color' => 'red']);
    $this->assertSame('red', $this->entityHelper->formatField($node, 'color'));
  }

  /**
   * @covers ::formatField
   */
  public function testDispatchBoolean(): void {
    $node = $this->createTestNode(['field_flag' => 1]);
    $this->assertTrue((bool) $this->entityHelper->formatField($node, 'flag'));
  }

  /**
   * @covers ::formatField
   */
  public function testDispatchFloat(): void {
    $node = $this->createTestNode(['field_rating' => 4.5]);
    $result = $this->entityHelper->formatField($node, 'rating');
    $value = is_array($result) ? (float) reset($result) : (float) $result;
    $this->assertEqualsWithDelta(4.5, $value, 0.0001);
  }

  /**
   * @covers ::formatField
   */
  public function testDispatchTaxonomyReference(): void {
    $term = \Drupal\taxonomy\Entity\Term::create(['vid' => 'tags', 'name' => 'Alpha']);
    $term->save();

    $node = $this->createTestNode([
      'field_tags' => [['target_id' => $term->id()]],
    ]);
    $result = $this->entityHelper->formatField($node, 'tags');
    $serialized = is_array($result) ? json_encode($result) : (string) $result;
    $this->assertStringContainsString('Alpha', $serialized);
  }

  /**
   * @covers ::formatField
   */
  public function testFormatFieldMissingReturnsFalse(): void {
    $node = $this->createTestNode();
    $this->assertFalse($this->entityHelper->formatField($node, 'nonexistent'));
  }

  /**
   * @covers ::formatFields
   *
   * Batch dispatch over a heterogeneous node: every field_* field
   * passes through formatField, base fields pass through getString().
   */
  public function testFormatFieldsBatch(): void {
    $node = $this->createTestNode([
      'field_headline' => 'Title',
      'field_color' => 'blue',
      'field_flag' => 1,
    ]);

    $result = $this->entityHelper->formatFields($node);

    // Field keys are stripped of 'field_' prefix.
    $this->assertArrayHasKey('headline', $result);
    $this->assertArrayHasKey('color', $result);
    $this->assertArrayHasKey('flag', $result);
    $this->assertSame('Title', $result['headline']);
    $this->assertSame('blue', $result['color']);
  }

  /**
   * @covers ::mapFields
   *
   * mapFields with empty map delegates to formatFields.
   */
  public function testMapFieldsEmptyMapDelegatesToFormatFields(): void {
    $node = $this->createTestNode(['field_headline' => 'X']);
    $result = $this->entityHelper->mapFields($node);
    $this->assertArrayHasKey('headline', $result);
    $this->assertSame('X', $result['headline']);
  }

  /**
   * @covers ::mapFields
   *
   * Simple string-based mapping: target_key => source_field_name.
   */
  public function testMapFieldsStringConfig(): void {
    $node = $this->createTestNode(['field_headline' => 'Mapped value']);
    $result = $this->entityHelper->mapFields($node, [
      'title' => 'headline',
    ]);
    $this->assertSame('Mapped value', $result['title']);
  }

}
