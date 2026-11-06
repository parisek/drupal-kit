<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Unit\Paragraphs;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\drupal_kit\Services\EntityHelper;
use Drupal\drupal_kit_paragraphs\Mapper\ParagraphComponentMapperInterface;
use Drupal\drupal_kit_paragraphs\Mapping\ParagraphMapping;
use Drupal\drupal_kit_paragraphs\Plugin\ExtraField\Display\ParagraphComponentDisplay;
use Drupal\paragraphs\ParagraphInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

require_once dirname(__DIR__, 3) . '/fixtures/stubs/ParagraphInterface.php';

/**
 * Tests for the paragraph_component ExtraField display.
 *
 * @group drupal_kit
 */
class ParagraphComponentDisplayTest extends TestCase {

  /**
   * Builds the display plugin with a mapper stub.
   */
  private function display(ParagraphComponentMapperInterface $mapper): ParagraphComponentDisplay {
    $display = new ParagraphComponentDisplay(
      [],
      'paragraph_component',
      ['provider' => 'drupal_kit_paragraphs'],
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(RouteMatchInterface::class),
      $this->createMock(LanguageManagerInterface::class),
      $this->createMock(EntityRepositoryInterface::class),
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(Connection::class),
      $this->createMock(EntityHelper::class),
      $this->createMock(PathMatcherInterface::class),
      $this->createMock(RequestStack::class),
      $this->createMock(TransliterationInterface::class),
    );
    (new \ReflectionProperty($display, 'mapper'))->setValue($display, $mapper);
    $display->setViewMode('teaser');
    return $display;
  }

  /**
   * The display returns the render array of the mapping.
   */
  public function testDisplayReturnsRenderArray(): void {
    $paragraph = $this->createMock(ParagraphInterface::class);
    $mapper = $this->createMock(ParagraphComponentMapperInterface::class);
    $mapper->expects($this->once())->method('map')->with($paragraph, 'teaser')
      ->willReturn((new ParagraphMapping('contact'))->set('phone', '1'));

    $build = $this->display($mapper)->view($paragraph);

    $this->assertSame('custom_component', $build['#theme']);
    $this->assertSame('contact', $build['#template']);
    $this->assertSame(['phone' => '1'], $build['#content']);
  }

  /**
   * An entity that is no paragraph renders nothing.
   */
  public function testDisplayIgnoresOtherEntities(): void {
    $mapper = $this->createMock(ParagraphComponentMapperInterface::class);
    $mapper->expects($this->never())->method('map');
    $entity = $this->createMock(ContentEntityInterface::class);

    $this->assertSame([], $this->display($mapper)->view($entity));
  }

}
