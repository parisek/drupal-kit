<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Unit\Paragraphs;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\drupal_kit_paragraphs\Display\ParagraphDisplaySwitcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the display switcher.
 *
 * @coversDefaultClass \Drupal\drupal_kit_paragraphs\Display\ParagraphDisplaySwitcher
 * @group drupal_kit
 */
class ParagraphDisplaySwitcherTest extends TestCase {

  private const LEGACY = 'extra_field_default_paragraph_display';

  private const COMPONENT = 'extra_field_paragraph_component';

  /**
   * The state double, a plain array.
   *
   * @var \ArrayObject<string, mixed>
   */
  private \ArrayObject $stateData;

  /**
   * The display double of the current test.
   */
  private EntityViewDisplay&MockObject $display;

  /**
   * Builds a switcher over a display with the given components.
   *
   * @param array<string, array<string, mixed>> $content
   *   The components of the display, keyed by name.
   * @param bool $exists
   *   Whether the storage finds the display.
   * @param string[] $extraFields
   *   The names of the extra fields the bundle has.
   */
  private function switcher(array $content, bool $exists = TRUE, array $extraFields = [self::LEGACY, self::COMPONENT]): ParagraphDisplaySwitcher {
    $this->display = $this->createMock(EntityViewDisplay::class);
    $this->display->method('getComponent')->willReturnCallback(static fn (string $name) => $content[$name] ?? NULL);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn($exists ? $this->display : NULL);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('entity_view_display')->willReturn($storage);

    $this->stateData = new \ArrayObject();
    $state = $this->createMock(StateInterface::class);
    $state->method('set')->willReturnCallback(function (string $key, mixed $value): void {
      $this->stateData[$key] = $value;
    });
    $state->method('delete')->willReturnCallback(function (string $key): void {
      unset($this->stateData[$key]);
    });
    $state->method('get')->willReturnCallback(fn (string $key) => $this->stateData[$key] ?? NULL);

    $fields = $this->createMock(EntityFieldManagerInterface::class);
    $fields->method('getExtraFields')->willReturn(['display' => array_fill_keys($extraFields, [])]);

    return new ParagraphDisplaySwitcher($manager, $state, $fields);
  }

  /**
   * Switching to the component swaps the field and remembers the legacy one.
   *
   * @covers ::switch
   */
  public function testToComponentSwapsFieldAndRemembersLegacy(): void {
    $legacy = ['weight' => 5, 'settings' => ['a' => 1], 'third_party_settings' => []];
    $switcher = $this->switcher([self::LEGACY => $legacy]);
    $this->display->expects($this->once())->method('removeComponent')->with(self::LEGACY);
    $this->display->expects($this->once())->method('setComponent')->with(
      self::COMPONENT,
      $this->callback(static fn (array $c): bool => $c['weight'] === 5),
    );
    $this->display->expects($this->once())->method('save');

    $result = $switcher->switch('contact', 'component');

    $this->assertTrue($result['changed']);
    $this->assertTrue($result['saved']);
    $key = 'drupal_kit_paragraphs.legacy_display.contact.default';
    $this->assertSame(['field' => self::LEGACY, 'config' => $legacy], $this->stateData[$key]);
  }

  /**
   * Switching back restores the remembered legacy settings.
   *
   * @covers ::switch
   */
  public function testToLegacyRestoresRemembered(): void {
    $switcher = $this->switcher([self::COMPONENT => ['weight' => 5]]);
    $remembered = ['weight' => 7, 'settings' => ['a' => 1], 'third_party_settings' => []];
    $key = 'drupal_kit_paragraphs.legacy_display.contact.default';
    $this->stateData[$key] = ['field' => self::LEGACY, 'config' => $remembered];
    $this->display->method('get')->with('hidden')->willReturn([self::COMPONENT => TRUE, 'field_x' => TRUE]);
    $this->display->expects($this->once())->method('set')->with('hidden', ['field_x' => TRUE]);
    $this->display->expects($this->once())->method('removeComponent')->with(self::COMPONENT);
    $this->display->expects($this->once())->method('setComponent')->with(self::LEGACY, $remembered);
    $this->display->expects($this->once())->method('save');

    $result = $switcher->switch('contact', 'legacy');

    $this->assertTrue($result['saved']);
    $this->assertArrayNotHasKey($key, $this->stateData);
  }

  /**
   * A display that shows both fields keeps its live legacy settings.
   *
   * @covers ::switch
   */
  public function testBothFieldsOnlyRemovesTheComponent(): void {
    $switcher = $this->switcher([
      self::COMPONENT => ['weight' => 1],
      self::LEGACY => ['weight' => 9],
    ]);
    $key = 'drupal_kit_paragraphs.legacy_display.contact.default';
    $this->stateData[$key] = ['field' => self::LEGACY, 'config' => ['weight' => 2]];
    $this->display->expects($this->once())->method('removeComponent')->with(self::COMPONENT);
    $this->display->expects($this->never())->method('setComponent');
    $this->display->expects($this->once())->method('save');

    $this->assertTrue($switcher->switch('contact', 'legacy')['saved']);
  }

  /**
   * A rollback with no State refuses a legacy field the bundle does not have.
   *
   * @covers ::switch
   */
  public function testRollbackToUnknownLegacyFieldIsRefused(): void {
    $extra = [self::COMPONENT, 'extra_field_custom_paragraph_display'];
    $switcher = $this->switcher([self::COMPONENT => ['weight' => 1]], TRUE, $extra);
    $this->display->expects($this->never())->method('save');

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('--legacy-field');
    $switcher->switch('contact', 'legacy');
  }

  /**
   * A rollback with no State works with the right --legacy-field.
   *
   * @covers ::switch
   */
  public function testRollbackWithoutStateUsesGivenLegacyField(): void {
    $custom = 'extra_field_custom_paragraph_display';
    $switcher = $this->switcher([self::COMPONENT => ['weight' => 3]], TRUE, [self::COMPONENT, $custom]);
    $expected = ['weight' => 3, 'settings' => [], 'third_party_settings' => []];
    $this->display->expects($this->once())->method('setComponent')->with($custom, $expected);
    $this->display->expects($this->once())->method('save');

    $this->assertTrue($switcher->switch('contact', 'legacy', 'default', $custom)['saved']);
  }

  /**
   * A dry run saves nothing and leaves no State behind.
   *
   * @covers ::switch
   */
  public function testDryRunSavesNothing(): void {
    $switcher = $this->switcher([self::LEGACY => ['weight' => 1]]);
    $this->display->expects($this->never())->method('save');

    $result = $switcher->switch('contact', 'component', 'default', self::LEGACY, TRUE);

    $this->assertTrue($result['changed']);
    $this->assertFalse($result['saved']);
    $this->assertCount(0, $this->stateData);
  }

  /**
   * A display that already has the target is left alone.
   *
   * @covers ::switch
   */
  public function testAlreadySwitchedChangesNothing(): void {
    $switcher = $this->switcher([self::COMPONENT => ['weight' => 1]]);
    $this->display->expects($this->never())->method('save');
    $this->assertFalse($switcher->switch('contact', 'component')['changed']);

    $switcher = $this->switcher([self::LEGACY => ['weight' => 1]]);
    $this->display->expects($this->never())->method('save');
    $this->assertFalse($switcher->switch('contact', 'legacy')['changed']);
  }

  /**
   * Bad input throws and saves nothing.
   *
   * @covers ::switch
   */
  public function testBadInputThrows(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->switcher([])->switch('contact', 'sideways');
  }

  /**
   * A missing display throws.
   *
   * @covers ::switch
   */
  public function testMissingDisplayThrows(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('does not exist');
    $this->switcher([], FALSE)->switch('contact', 'component');
  }

  /**
   * A display with neither field throws, for both targets.
   *
   * @covers ::switch
   */
  public function testNeitherFieldThrows(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('shows neither');
    $this->switcher([])->switch('contact', 'legacy');
  }

}
