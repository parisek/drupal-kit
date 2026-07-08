<?php

namespace Drupal\Tests\drupal_kit\Unit;

use Drupal\drupal_kit\DisplayBase;
use Drupal\drupal_kit\Services\EntityHelper;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Component\Transliteration\TransliterationInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the DisplayBase abstract class.
 *
 * @coversDefaultClass \Drupal\drupal_kit\DisplayBase
 * @group drupal_kit
 */
class DisplayBaseTest extends TestCase {

  /**
   * The mock EntityHelper.
   */
  protected EntityHelper $entityHelper;

  /**
   * The testable display instance.
   */
  protected TestableDisplay $display;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityHelper = $this->createMock(EntityHelper::class);

    $this->display = new TestableDisplay(
      [],
      'test_plugin',
      ['provider' => 'test'],
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(RouteMatchInterface::class),
      $this->createMock(LanguageManagerInterface::class),
      $this->createMock(EntityRepositoryInterface::class),
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(Connection::class),
      $this->entityHelper,
      $this->createMock(PathMatcherInterface::class),
      $this->createMock(RequestStack::class),
      $this->createMock(TransliterationInterface::class),
    );
  }

  /**
   * @covers ::__call
   */
  public function testCallDelegatesToGetTextField(): void {
    $entity = $this->createMock(ContentEntityInterface::class);
    $this->entityHelper->expects($this->once())
      ->method('getTextField')
      ->with($entity, 'title', [])
      ->willReturn('Hello');

    $result = $this->display->getTextField($entity, 'title');
    $this->assertSame('Hello', $result);
  }

  /**
   * @covers ::__call
   */
  public function testCallDelegatesToGetMediaField(): void {
    $entity = $this->createMock(ContentEntityInterface::class);
    $this->entityHelper->expects($this->once())
      ->method('getMediaField')
      ->with($entity, 'image', ['return_format' => 'array'])
      ->willReturn([['src' => 'test.jpg']]);

    $result = $this->display->getMediaField($entity, 'image', ['return_format' => 'array']);
    $this->assertIsArray($result);
  }

  /**
   * @covers ::__call
   */
  public function testCallDelegatesToGetMenu(): void {
    $this->entityHelper->expects($this->once())
      ->method('getMenu')
      ->with('main', ['depth' => 2])
      ->willReturn([['title' => 'Home']]);

    $result = $this->display->getMenu('main', ['depth' => 2]);
    $this->assertCount(1, $result);
  }

  /**
   * @covers ::__call
   */
  public function testCallDelegatesToGetTaxonomy(): void {
    $this->entityHelper->expects($this->once())
      ->method('getTaxonomy')
      ->with('tags', [])
      ->willReturn([]);

    $result = $this->display->getTaxonomy('tags');
    $this->assertIsArray($result);
  }

  /**
   * @covers ::__call
   */
  public function testCallDelegatesSvgDimensions(): void {
    $this->entityHelper->expects($this->once())
      ->method('getSvgViewBoxDimensions')
      ->with('public://icon.svg')
      ->willReturn(['width' => 24, 'height' => 24]);

    $result = $this->display->getSvgViewBoxDimensions('public://icon.svg');
    $this->assertSame(24, $result['width']);
  }

  /**
   * @covers ::__call
   */
  public function testEmailFieldDelegatesToGetTextField(): void {
    $entity = $this->createMock(ContentEntityInterface::class);
    $this->entityHelper->expects($this->once())
      ->method('getTextField')
      ->with($entity, 'email', [])
      ->willReturn('test@example.com');

    $result = $this->display->getEmailField($entity, 'email');
    $this->assertSame('test@example.com', $result);
  }

  /**
   * @covers ::__call
   */
  public function testPhoneFieldDelegatesToGetTextField(): void {
    $entity = $this->createMock(ContentEntityInterface::class);
    $this->entityHelper->expects($this->once())
      ->method('getTextField')
      ->with($entity, 'phone', [])
      ->willReturn('+420123456');

    $result = $this->display->getPhoneField($entity, 'phone');
    $this->assertSame('+420123456', $result);
  }

  /**
   * Verify getTextareaField delegates to getTextareaField, not getTextField.
   *
   * @covers ::__call
   */
  public function testTextareaFieldDelegatesToGetTextareaField(): void {
    $entity = $this->createMock(ContentEntityInterface::class);
    $this->entityHelper->expects($this->once())
      ->method('getTextareaField')
      ->with($entity, 'summary')
      ->willReturn('<p>Rich text</p>');

    // If this were aliased to getTextField, getTextareaField would
    // never be called.
    $result = $this->display->getTextareaField($entity, 'summary');
    $this->assertSame('<p>Rich text</p>', $result);
  }

  /**
   * Verify mapFields delegates correctly.
   *
   * @covers ::__call
   */
  public function testCallDelegatesToMapFields(): void {
    $entity = $this->createMock(ContentEntityInterface::class);
    $map = ['title' => 'title', 'image' => 'media'];
    $expected = ['title' => 'Test', 'image' => ['src' => '/img.jpg']];

    $this->entityHelper->expects($this->once())
      ->method('mapFields')
      ->with($entity, $map)
      ->willReturn($expected);

    $result = $this->display->mapFields($entity, $map);
    $this->assertSame($expected, $result);
  }

  /**
   * @covers ::__call
   */
  public function testCallThrowsForUnknownMethod(): void {
    $this->expectException(\BadMethodCallException::class);
    $this->display->nonExistentMethod();
  }

  /**
   * @covers ::__call
   */
  public function testCallPassesReturnValue(): void {
    $entity = $this->createMock(ContentEntityInterface::class);
    $this->entityHelper->expects($this->once())
      ->method('getBooleanField')
      ->with($entity, 'active')
      ->willReturn(TRUE);

    $result = $this->display->getBooleanField($entity, 'active');
    $this->assertTrue($result);
  }

}

/**
 * Concrete test implementation of DisplayBase.
 */
class TestableDisplay extends DisplayBase {

  /**
   * {@inheritdoc}
   */
  public function view(ContentEntityInterface $entity) {
    return [];
  }

}
