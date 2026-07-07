<?php

namespace Drupal\Tests\custom_components\Kernel\Services;

use Drupal\Tests\custom_components\Kernel\EntityHelperFieldsKernelTestBase;

/**
 * @coversDefaultClass \Drupal\custom_components\Services\EntityHelper
 * @group custom_components
 */
class EntityHelperLinkFieldsKernelTest extends EntityHelperFieldsKernelTestBase {

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
