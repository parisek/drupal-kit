<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Unit\Commands;

use Drupal\drupal_kit\Commands\ConfigApplierCommands;
use Drupal\drupal_kit\Services\ConfigApplier;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the `drush kit:config-apply` option parsing / wiring.
 *
 * ConfigApplier itself is mocked; the behavior under test is entirely in
 * this class — splitting --names, reading --names-file, parsing
 * --expect-hash pairs, and refusing to run with no names at all.
 *
 * @group drupal_kit
 */
final class ConfigApplierCommandsTest extends TestCase {

  /**
   * Names from comma separated option.
   */
  public function testNamesFromCommaSeparatedOption(): void {
    $applier = $this->createMock(ConfigApplier::class);
    $applier->expects($this->once())
      ->method('apply')
      ->with('config/sync', ['a.b', 'c.d'], [], [], FALSE)
      ->willReturn([['name' => 'a.b', 'action' => 'CREATE', 'reason' => '']]);

    $command = new ConfigApplierCommands($applier);
    $result = $command->configApply([
      'config-dir' => 'config/sync',
      'names' => 'a.b, c.d',
      'names-file' => '',
      'update' => '',
      'expect-hash' => [],
      'dry-run' => FALSE,
    ]);

    $this->assertSame([['name' => 'a.b', 'action' => 'CREATE', 'reason' => '']], $result->getArrayCopy());
  }

  /**
   * Names file is merged with names option.
   */
  public function testNamesFileIsMergedWithNamesOption(): void {
    $file = tempnam(sys_get_temp_dir(), 'kit_names_');
    $this->assertIsString($file);
    file_put_contents($file, "# comment\na.b\n\nc.d\n");

    $applier = $this->createMock(ConfigApplier::class);
    $applier->expects($this->once())
      ->method('apply')
      ->with('config/sync', ['e.f', 'a.b', 'c.d'], [], [], FALSE)
      ->willReturn([]);

    $command = new ConfigApplierCommands($applier);
    $command->configApply([
      'config-dir' => 'config/sync',
      'names' => 'e.f',
      'names-file' => $file,
      'update' => '',
      'expect-hash' => [],
      'dry-run' => FALSE,
    ]);

    unlink($file);
  }

  /**
   * Throws without any names.
   */
  public function testThrowsWithoutAnyNames(): void {
    $applier = $this->createMock(ConfigApplier::class);
    $applier->expects($this->never())->method('apply');

    $command = new ConfigApplierCommands($applier);

    $this->expectException(\InvalidArgumentException::class);
    $command->configApply([
      'config-dir' => 'config/sync',
      'names' => '',
      'names-file' => '',
      'update' => '',
      'expect-hash' => [],
      'dry-run' => FALSE,
    ]);
  }

  /**
   * Update and expect hash are passed through.
   */
  public function testUpdateAndExpectHashArePassedThrough(): void {
    $applier = $this->createMock(ConfigApplier::class);
    $applier->expects($this->once())
      ->method('apply')
      ->with('config/sync', ['a.b'], ['a.b'], ['a.b' => 'deadbeef'], TRUE)
      ->willReturn([]);

    $command = new ConfigApplierCommands($applier);
    $command->configApply([
      'config-dir' => 'config/sync',
      'names' => 'a.b',
      'names-file' => '',
      'update' => 'a.b',
      'expect-hash' => ['a.b=deadbeef'],
      'dry-run' => TRUE,
    ]);
  }

  /**
   * Expect hash accepts comma separated pairs too.
   */
  public function testExpectHashAcceptsCommaSeparatedPairsToo(): void {
    $applier = $this->createMock(ConfigApplier::class);
    $applier->expects($this->once())
      ->method('apply')
      ->with('config/sync', ['a.b'], [], ['a.b' => 'aaa', 'c.d' => 'bbb'], FALSE)
      ->willReturn([]);

    $command = new ConfigApplierCommands($applier);
    $command->configApply([
      'config-dir' => 'config/sync',
      'names' => 'a.b',
      'names-file' => '',
      'update' => '',
      'expect-hash' => 'a.b=aaa,c.d=bbb',
      'dry-run' => FALSE,
    ]);
  }

  /**
   * Malformed expect hash throws.
   */
  public function testMalformedExpectHashThrows(): void {
    $applier = $this->createMock(ConfigApplier::class);
    $command = new ConfigApplierCommands($applier);

    $this->expectException(\InvalidArgumentException::class);
    $command->configApply([
      'config-dir' => 'config/sync',
      'names' => 'a.b',
      'names-file' => '',
      'update' => '',
      'expect-hash' => ['a.b'],
      'dry-run' => FALSE,
    ]);
  }

  /**
   * Unreadable names file throws.
   */
  public function testUnreadableNamesFileThrows(): void {
    $applier = $this->createMock(ConfigApplier::class);
    $command = new ConfigApplierCommands($applier);

    $this->expectException(\InvalidArgumentException::class);
    $command->configApply([
      'config-dir' => 'config/sync',
      'names' => '',
      'names-file' => '/nonexistent/path/kit-names.txt',
      'update' => '',
      'expect-hash' => [],
      'dry-run' => FALSE,
    ]);
  }

}
