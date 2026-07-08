<?php

namespace Drupal\Tests\drupal_kit\Kernel\Services;

use Drupal\Tests\drupal_kit\Kernel\EntityHelperFieldsKernelTestBase;

/**
 * @coversDefaultClass \Drupal\drupal_kit\Services\EntityHelper
 * @group drupal_kit
 */
class EntityHelperLinkFieldsKernelTest extends EntityHelperFieldsKernelTestBase {

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
    'link',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->attachField('cta', 'link', [], [
      'title' => 1,
      'link_type' => 17,
    ]);
  }

  /**
   * @covers ::getLinkField
   */
  public function testGetLinkFieldExternalUriReturnsTitleAndUrl(): void {
    $node = $this->createTestNode([
      'field_cta' => [
        'uri' => 'https://example.com/about',
        'title' => 'Learn more',
      ],
    ]);

    $value = $this->entityHelper->getLinkField($node, 'cta');
    $serialized = is_array($value) ? json_encode($value) : (string) $value;

    $this->assertStringContainsString('Learn more', $serialized);
    $this->assertStringContainsString('example.com', $serialized);
  }

  /**
   * @covers ::getLinkField
   */
  public function testGetLinkFieldEmptyReturnsEmptyOrArray(): void {
    $node = $this->createTestNode();
    $value = $this->entityHelper->getLinkField($node, 'cta');
    $this->assertTrue($value === [] || $value === '');
  }

  /**
   * @covers ::getLinkField
   */
  public function testGetLinkFieldInternalUriResolves(): void {
    $node = $this->createTestNode([
      'field_cta' => [
        'uri' => 'internal:/contact',
        'title' => 'Contact',
      ],
    ]);

    $value = $this->entityHelper->getLinkField($node, 'cta');
    $serialized = is_array($value) ? json_encode($value) : (string) $value;
    $this->assertStringContainsString('Contact', $serialized);
  }

}
