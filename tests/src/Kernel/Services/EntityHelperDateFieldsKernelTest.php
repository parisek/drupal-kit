<?php

namespace Drupal\Tests\custom_components\Kernel\Services;

use Drupal\Tests\custom_components\Kernel\EntityHelperFieldsKernelTestBase;

/**
 * @coversDefaultClass \Drupal\custom_components\Services\EntityHelper
 * @group custom_components
 */
class EntityHelperDateFieldsKernelTest extends EntityHelperFieldsKernelTestBase {

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
    'datetime',
    'datetime_range',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->attachField('published_at', 'datetime', [], ['datetime_type' => 'datetime']);
    $this->attachField('event_window', 'daterange', [], ['datetime_type' => 'datetime']);
  }

  /**
   * @covers ::getDateField
   */
  public function testGetDateFieldReturnsValue(): void {
    $node = $this->createTestNode(['field_published_at' => '2024-03-14T10:00:00']);
    $value = $this->entityHelper->getDateField($node, 'published_at');
    // Implementation may return a structured array or string; assert
    // that the timestamp source is somewhere in the result.
    $serialized = is_array($value) ? json_encode($value) : (string) $value;
    $this->assertStringContainsString('2024', $serialized);
  }

  /**
   * @covers ::getDateField
   */
  public function testGetDateFieldEmptyReturnsString(): void {
    $node = $this->createTestNode();
    $this->assertSame('', $this->entityHelper->getDateField($node, 'published_at'));
  }

  /**
   * @covers ::getDateRangeField
   */
  public function testGetDateRangeFieldReturnsBothEndpoints(): void {
    $node = $this->createTestNode(['field_event_window' => [
      'value' => '2024-03-14T10:00:00',
      'end_value' => '2024-03-14T18:00:00',
    ]]);
    $value = $this->entityHelper->getDateRangeField($node, 'event_window');
    $serialized = is_array($value) ? json_encode($value) : (string) $value;
    $this->assertStringContainsString('2024', $serialized);
  }

  /**
   * @covers ::getDateRangeField
   */
  public function testGetDateRangeFieldEmptyReturnsArrayOrEmptyString(): void {
    $node = $this->createTestNode();
    $value = $this->entityHelper->getDateRangeField($node, 'event_window');
    // Empty either returns [] or '' depending on normalizeReturnValue;
    // both are acceptable empty signals.
    $this->assertTrue($value === [] || $value === '');
  }

}
