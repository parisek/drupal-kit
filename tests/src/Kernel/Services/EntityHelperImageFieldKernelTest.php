<?php

namespace Drupal\Tests\custom_components\Kernel\Services;

use Drupal\Tests\custom_components\Kernel\EntityHelperMediaFieldsKernelTestBase;

/**
 * @coversDefaultClass \Drupal\custom_components\Services\EntityHelper
 * @group custom_components
 */
class EntityHelperImageFieldKernelTest extends EntityHelperMediaFieldsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->attachField('hero', 'image');
  }

  /**
   * @covers ::getImageField
   */
  public function testGetImageFieldReturnsImageArray(): void {
    $file = $this->createTestPngFile('hero.png');
    $node = $this->createTestNode([
      'field_hero' => ['target_id' => $file->id(), 'alt' => 'Hero alt', 'title' => 'Hero title'],
    ]);

    $result = $this->entityHelper->getImageField($node, 'hero');

    // Single image returned via normalizeReturnValue; could be unwrapped.
    $image = is_array($result) && isset($result[0]) ? $result[0] : $result;
    $this->assertIsArray($image);
    $this->assertArrayHasKey('src', $image);
    $this->assertStringContainsString('hero.png', $image['src']);
    $this->assertSame('image/png', $image['type']);
    $this->assertSame('Hero alt', $image['alt']);
  }

  /**
   * @covers ::getImageField
   */
  public function testGetImageFieldEmptyReturnsEmpty(): void {
    $node = $this->createTestNode();
    $result = $this->entityHelper->getImageField($node, 'hero');
    $this->assertTrue($result === [] || $result === '');
  }

  /**
   * @covers ::getImageField
   */
  public function testGetImageFieldMissingFieldReturnsFalse(): void {
    $node = $this->createTestNode();
    $this->assertFalse($this->entityHelper->getImageField($node, 'nonexistent'));
  }

}
