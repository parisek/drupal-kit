<?php

namespace Drupal\Tests\drupal_kit\Kernel\Services;

use Drupal\Tests\drupal_kit\Kernel\EntityHelperMediaFieldsKernelTestBase;

/**
 * @coversDefaultClass \Drupal\drupal_kit\Services\EntityHelper
 * @group drupal_kit
 */
class EntityHelperFileFieldKernelTest extends EntityHelperMediaFieldsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->attachField('attachment', 'file');
  }

  /**
   * @covers ::getFileField
   */
  public function testGetFileFieldReturnsFileMetadata(): void {
    $file = $this->createTestPngFile('doc.png');
    $node = $this->createTestNode([
      'field_attachment' => ['target_id' => $file->id(), 'description' => 'A document'],
    ]);

    $result = $this->entityHelper->getFileField($node, 'attachment');
    $item = is_array($result) && isset($result[0]) ? $result[0] : $result;

    $this->assertIsArray($item);
    $this->assertSame('A document', $item['title']);
    $this->assertSame('doc.png', $item['filename']);
    $this->assertSame('image/png', $item['type']);
  }

  /**
   * @covers ::getFileField
   *
   * When description is empty, title falls back to the file's filename.
   */
  public function testGetFileFieldFallsBackToFilenameWhenNoDescription(): void {
    $file = $this->createTestPngFile('noname.png');
    $node = $this->createTestNode([
      'field_attachment' => ['target_id' => $file->id()],
    ]);

    $result = $this->entityHelper->getFileField($node, 'attachment');
    $item = is_array($result) && isset($result[0]) ? $result[0] : $result;

    $this->assertSame('noname.png', $item['title']);
  }

  /**
   * @covers ::getFileField
   */
  public function testGetFileFieldEmptyReturnsEmpty(): void {
    $node = $this->createTestNode();
    $result = $this->entityHelper->getFileField($node, 'attachment');
    $this->assertTrue($result === [] || $result === '');
  }

  /**
   * @covers ::getFileField
   */
  public function testGetFileFieldMissingFieldReturnsFalse(): void {
    $node = $this->createTestNode();
    $this->assertFalse($this->entityHelper->getFileField($node, 'nonexistent'));
  }

}
