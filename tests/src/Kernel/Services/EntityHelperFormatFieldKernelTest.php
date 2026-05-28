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
    'telephone',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['filter']);

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

    // Additional field types — each maps to a distinct case in
    // EntityHelper::dispatchByFieldType. Attaching them ensures the
    // dispatch switch is fully exercised by the per-type tests below.
    $this->attachField('summary_long', 'string_long');
    $this->attachField('contact_email', 'email');
    $this->attachField('contact_phone', 'telephone');
    $this->attachField('quantity', 'integer');
    $this->attachField('price_decimal', 'decimal', [], ['precision' => 10, 'scale' => 2]);
    $this->attachField('intro_text', 'text');
    $this->attachField('long_text_summary', 'text_with_summary');
    $this->attachField('priority', 'list_integer', [
      'allowed_values' => [1 => 'Low', 2 => 'Medium', 3 => 'High'],
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
   * @covers ::dispatchByFieldType
   *
   * `string_long` shares the getTextField dispatch with `string`,
   * `email`, `telephone`, `integer`, `decimal`. Each case in the
   * switch needs an executed line to credit the dispatcher branch.
   */
  public function testDispatchStringLong(): void {
    $node = $this->createTestNode(['field_summary_long' => 'Long single-line summary that exceeds 255 chars-style use case.']);
    $this->assertStringContainsString('Long single-line', (string) $this->entityHelper->formatField($node, 'summary_long'));
  }

  /**
   * @covers ::formatField
   * @covers ::dispatchByFieldType
   */
  public function testDispatchEmail(): void {
    $node = $this->createTestNode(['field_contact_email' => 'hello@example.test']);
    $this->assertSame('hello@example.test', $this->entityHelper->formatField($node, 'contact_email'));
  }

  /**
   * @covers ::formatField
   * @covers ::dispatchByFieldType
   */
  public function testDispatchTelephone(): void {
    $node = $this->createTestNode(['field_contact_phone' => '+420 555 123 456']);
    $this->assertSame('+420 555 123 456', $this->entityHelper->formatField($node, 'contact_phone'));
  }

  /**
   * @covers ::formatField
   * @covers ::dispatchByFieldType
   */
  public function testDispatchInteger(): void {
    $node = $this->createTestNode(['field_quantity' => 42]);
    $result = $this->entityHelper->formatField($node, 'quantity');
    $value = is_array($result) ? (int) reset($result) : (int) $result;
    $this->assertSame(42, $value);
  }

  /**
   * @covers ::formatField
   * @covers ::dispatchByFieldType
   */
  public function testDispatchDecimal(): void {
    $node = $this->createTestNode(['field_price_decimal' => '19.95']);
    $result = $this->entityHelper->formatField($node, 'price_decimal');
    $value = is_array($result) ? (string) reset($result) : (string) $result;
    $this->assertSame('19.95', $value);
  }

  /**
   * @covers ::formatField
   * @covers ::dispatchByFieldType
   *
   * `text`, `text_long`, `text_with_summary` all route to
   * getTextareaField. text_long is already covered by
   * testDispatchTextLong above; this pins `text` separately.
   */
  public function testDispatchText(): void {
    $node = $this->createTestNode(['field_intro_text' => [
      'value' => 'Intro paragraph',
      'format' => 'plain_text',
    ]]);
    $result = $this->entityHelper->formatField($node, 'intro_text');
    $this->assertStringContainsString('Intro paragraph', (string) $result);
  }

  /**
   * @covers ::formatField
   * @covers ::dispatchByFieldType
   */
  public function testDispatchTextWithSummary(): void {
    $node = $this->createTestNode(['field_long_text_summary' => [
      'value' => 'Body with a separate summary.',
      'summary' => 'Short summary.',
      'format' => 'plain_text',
    ]]);
    $result = $this->entityHelper->formatField($node, 'long_text_summary');
    $this->assertStringContainsString('Body with a separate summary.', (string) $result);
  }

  /**
   * @covers ::formatField
   * @covers ::dispatchByFieldType
   *
   * `list_integer` shares the getSelectField dispatch with
   * `list_string`.
   */
  public function testDispatchListInteger(): void {
    $node = $this->createTestNode(['field_priority' => 2]);
    $result = $this->entityHelper->formatField($node, 'priority');
    $this->assertSame(2, (int) $result);
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
