<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Unit\Paragraphs;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\drupal_kit_paragraphs\Display\ParagraphComponentUninstallValidator;

/**
 * Tests for the uninstall validator.
 *
 * @coversDefaultClass \Drupal\drupal_kit_paragraphs\Display\ParagraphComponentUninstallValidator
 * @group drupal_kit
 */
class ParagraphComponentUninstallValidatorTest extends UnitTestCase {

  /**
   * Builds a validator over displays that have the given components.
   *
   * @param array<int, array{bundle: string, mode: string, has: bool}> $displays
   *   The displays.
   */
  private function validator(array $displays): ParagraphComponentUninstallValidator {
    $doubles = [];
    foreach ($displays as $spec) {
      $display = $this->createMock(EntityViewDisplay::class);
      $display->method('getComponent')->willReturn($spec['has'] ? ['weight' => 0] : NULL);
      $display->method('getTargetBundle')->willReturn($spec['bundle']);
      $display->method('getMode')->willReturn($spec['mode']);
      $doubles[] = $display;
    }
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadByProperties')->with(['targetEntityType' => 'paragraph'])->willReturn($doubles);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('entity_view_display')->willReturn($storage);

    return new ParagraphComponentUninstallValidator($manager, $this->getStringTranslationStub());
  }

  /**
   * The reason names each display that uses the component and the command.
   *
   * @covers ::validate
   */
  public function testReasonNamesDisplaysAndCommands(): void {
    $reasons = $this->validator([
      ['bundle' => 'contact', 'mode' => 'default', 'has' => TRUE],
      ['bundle' => 'hero', 'mode' => 'default', 'has' => FALSE],
      ['bundle' => 'signpost', 'mode' => 'full', 'has' => TRUE],
    ])->validate('drupal_kit_paragraphs');

    $this->assertCount(1, $reasons);
    $text = (string) $reasons[0];
    $this->assertStringContainsString('drupal-kit:paragraph-switch contact legacy --view-mode=default', $text);
    $this->assertStringContainsString('drupal-kit:paragraph-switch signpost legacy --view-mode=full', $text);
    $this->assertStringNotContainsString('hero', $text);
  }

  /**
   * No display uses the component: nothing blocks the uninstall.
   *
   * @covers ::validate
   */
  public function testNoReasonWhenUnused(): void {
    $this->assertSame([], $this->validator([['bundle' => 'hero', 'mode' => 'default', 'has' => FALSE]])->validate('drupal_kit_paragraphs'));
  }

  /**
   * Other modules are not this validator's business.
   *
   * @covers ::validate
   */
  public function testOtherModulesAreIgnored(): void {
    $this->assertSame([], $this->validator([['bundle' => 'contact', 'mode' => 'default', 'has' => TRUE]])->validate('node'));
  }

}
