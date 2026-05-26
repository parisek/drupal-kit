<?php

namespace Drupal\Tests\custom_components\Kernel\Services;

use Drupal\Tests\custom_components\Kernel\EntityHelperFieldsKernelTestBase;

/**
 * Behavioral tests for EntityHelper's text-shaped field getters.
 *
 * Covers: getTextField, getTextareaField, getSelectField, getDoubleField,
 * getBooleanField against a single test_article node with one field of
 * each type.
 *
 * @coversDefaultClass \Drupal\custom_components\Services\EntityHelper
 * @group custom_components
 */
class EntityHelperTextFieldsKernelTest extends EntityHelperFieldsKernelTestBase {

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
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Single-value string.
    $this->attachField('headline', 'string');
    // Multi-value string for return_format=array path.
    $this->attachField('tags', 'string', ['cardinality' => -1]);
    // Long text (textarea). Field name avoids 'body' because
    // getTextareaField exempts that name from the field_ prefix
    // normalization for Node entities (real Drupal body fields are
    // unprefixed). Using 'content' keeps the prefix path intact.
    $this->attachField('content', 'text_long');
    // Numeric (float).
    $this->attachField('score', 'float');
    // Boolean.
    $this->attachField('is_featured', 'boolean');
    // Select (list_string).
    $this->attachField('color', 'list_string', [
      'allowed_values' => ['red' => 'Red', 'blue' => 'Blue'],
    ]);
  }

  /**
   * @covers ::getTextField
   */
  public function testGetTextFieldReturnsValue(): void {
    $node = $this->createTestNode(['field_headline' => 'Hello world']);
    $this->assertSame('Hello world', $this->entityHelper->getTextField($node, 'headline'));
  }

  /**
   * @covers ::getTextField
   */
  public function testGetTextFieldEmptyReturnsEmptyString(): void {
    $node = $this->createTestNode();
    $this->assertSame('', $this->entityHelper->getTextField($node, 'headline'));
  }

  /**
   * @covers ::getTextField
   */
  public function testGetTextFieldMissingFieldReturnsFalse(): void {
    $node = $this->createTestNode();
    $this->assertFalse($this->entityHelper->getTextField($node, 'nonexistent'));
  }

  /**
   * @covers ::getTextField
   */
  public function testGetTextFieldReturnFormatArrayWrapsEvenSingleValue(): void {
    $node = $this->createTestNode(['field_tags' => ['alpha']]);
    $result = $this->entityHelper->getTextField($node, 'tags', ['return_format' => 'array']);
    $this->assertSame(['alpha'], $result);
  }

  /**
   * @covers ::getTextField
   */
  public function testGetTextFieldMultivalueReturnsArrayByDefault(): void {
    $node = $this->createTestNode(['field_tags' => ['alpha', 'beta']]);
    $result = $this->entityHelper->getTextField($node, 'tags');
    $this->assertSame(['alpha', 'beta'], $result);
  }

  /**
   * @covers ::getTextareaField
   */
  public function testGetTextareaFieldReturnsValue(): void {
    $node = $this->createTestNode(['field_content' => [
      'value' => 'Long content here',
      'format' => 'plain_text',
    ]]);
    // Renderer wraps in <p>; we just want substring presence.
    $result = $this->entityHelper->getTextareaField($node, 'content');
    $this->assertStringContainsString('Long content here', (string) $result);
  }

  /**
   * @covers ::getTextareaField
   */
  public function testGetTextareaFieldEmptyReturnsEmptyString(): void {
    $node = $this->createTestNode();
    $this->assertSame('', $this->entityHelper->getTextareaField($node, 'content'));
  }

  /**
   * @covers ::getDoubleField
   */
  public function testGetDoubleFieldReturnsValue(): void {
    $node = $this->createTestNode(['field_score' => 4.2]);
    // Drupal stores floats as string; getDoubleField returns
    // structured array under the documented contract — at minimum the
    // returned value should equal 4.2 numerically.
    $value = $this->entityHelper->getDoubleField($node, 'score');
    // Single-item shape: float, possibly wrapped — assert numeric equality.
    if (is_array($value)) {
      $value = reset($value);
    }
    $this->assertEqualsWithDelta(4.2, (float) $value, 0.0001);
  }

  /**
   * @covers ::getDoubleField
   */
  public function testGetDoubleFieldEmptyReturnsArray(): void {
    $node = $this->createTestNode();
    $this->assertSame([], $this->entityHelper->getDoubleField($node, 'score'));
  }

  /**
   * @covers ::getBooleanField
   */
  public function testGetBooleanFieldReturnsTrue(): void {
    $node = $this->createTestNode(['field_is_featured' => 1]);
    $this->assertTrue((bool) $this->entityHelper->getBooleanField($node, 'is_featured'));
  }

  /**
   * @covers ::getBooleanField
   */
  public function testGetBooleanFieldReturnsFalse(): void {
    $node = $this->createTestNode(['field_is_featured' => 0]);
    // Field is present and explicit FALSE — return value should be falsy.
    $this->assertFalse((bool) $this->entityHelper->getBooleanField($node, 'is_featured'));
  }

  /**
   * @covers ::getSelectField
   */
  public function testGetSelectFieldReturnsValue(): void {
    $node = $this->createTestNode(['field_color' => 'red']);
    $this->assertSame('red', $this->entityHelper->getSelectField($node, 'color'));
  }

  /**
   * @covers ::getSelectField
   */
  public function testGetSelectFieldEmptyReturnsEmptyString(): void {
    $node = $this->createTestNode();
    $this->assertSame('', $this->entityHelper->getSelectField($node, 'color'));
  }

}
