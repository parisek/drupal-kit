<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Kernel\Services;

use Drupal\Core\Config\FileStorage;
use Drupal\Core\Utility\UpdateException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\drupal_kit\Services\ConfigApplier;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;

/**
 * Kernel tests for ConfigApplier::planSlice() and applySlice() (#163).
 *
 * A slice is a directory with the config files, a names.txt and, optionally,
 * an update.txt and a hashes.txt. applySlice() plans the whole slice first
 * and writes nothing when one entry is ERROR or REFUSE.
 *
 * @group drupal_kit
 */
final class ConfigApplierSliceKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['drupal_kit', 'system', 'user', 'field', 'text', 'node'];

  /**
   * The ConfigApplier service under test.
   */
  protected ConfigApplier $configApplier;

  /**
   * The shared fixture config, read from tests/fixtures/config.
   */
  protected FileStorage $fixtures;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['field']);

    $this->configApplier = $this->container->get('drupal_kit.config_applier');
    $this->fixtures = new FileStorage(__DIR__ . '/../../../fixtures/config');
  }

  /**
   * Builds a slice directory in the temp dir.
   *
   * @param string[] $names
   *   Fixture config names to copy into the slice.
   * @param array<string, string> $lists
   *   File name => content, e.g. 'names.txt' => "a\nb\n".
   *
   * @return string
   *   The slice directory.
   */
  private function slice(array $names, array $lists): string {
    $dir = sys_get_temp_dir() . '/' . $this->getRandomGenerator()->name();
    mkdir($dir);
    $target = new FileStorage($dir);
    foreach ($names as $name) {
      $record = $this->fixtures->read($name);
      $this->assertIsArray($record);
      $target->write($name, $record);
    }
    foreach ($lists as $file => $content) {
      file_put_contents("$dir/$file", $content);
    }
    return $dir;
  }

  /**
   * The three fixture config names.
   *
   * @return string[]
   *   The config names.
   */
  private function names(): array {
    return [
      'node.type.kit_page',
      'field.storage.node.field_kit_teaser',
      'field.field.node.kit_page.field_kit_teaser',
    ];
  }

  /**
   * Asserts that neither the bundle nor the field storage exists.
   */
  private function assertNothingCreated(): void {
    $this->assertNull(NodeType::load('kit_page'));
    $this->assertNull(FieldStorageConfig::loadByName('node', 'field_kit_teaser'));
  }

  /**
   * Names.txt drives the slice; comments and blank lines are skipped.
   */
  public function testApplySliceCreatesEveryName(): void {
    $dir = $this->slice($this->names(), [
      'names.txt' => "# Kit page\n\nfield.field.node.kit_page.field_kit_teaser\n  node.type.kit_page  \nfield.storage.node.field_kit_teaser\n",
    ]);

    $plan = $this->configApplier->applySlice($dir);

    $this->assertSame($this->names(), array_column($plan, 'name'));
    $this->assertSame(
      [ConfigApplier::ACTION_CREATE, ConfigApplier::ACTION_CREATE, ConfigApplier::ACTION_CREATE],
      array_column($plan, 'action'),
    );
    $this->assertNotNull(FieldConfig::loadByName('node', 'kit_page', 'field_kit_teaser'));
  }

  /**
   * A second run reports SKIP-EXISTS and does not throw.
   */
  public function testApplySliceIsIdempotent(): void {
    $dir = $this->slice($this->names(), ['names.txt' => implode("\n", $this->names())]);

    $this->configApplier->applySlice($dir);
    $plan = $this->configApplier->applySlice($dir);

    $this->assertSame(
      [ConfigApplier::ACTION_SKIP_EXISTS, ConfigApplier::ACTION_SKIP_EXISTS, ConfigApplier::ACTION_SKIP_EXISTS],
      array_column($plan, 'action'),
    );
  }

  /**
   * One ERROR entry stops the slice before the valid entries are written.
   *
   * This is the gap apply() leaves open: it writes every CREATE entry of a
   * plan that also holds an ERROR.
   */
  public function testErrorEntryStopsTheWholeSlice(): void {
    $dir = $this->slice($this->names(), [
      'names.txt' => "node.type.kit_page\nfield.storage.node.field_kit_teaser\nnode.type.not_in_the_slice\n",
    ]);

    try {
      $this->configApplier->applySlice($dir);
      $this->fail('applySlice() did not throw on an ERROR entry.');
    }
    catch (UpdateException $e) {
      $this->assertStringContainsString('node.type.not_in_the_slice', $e->getMessage());
      $this->assertStringContainsString('Nothing changed', $e->getMessage());
    }
    $this->assertNothingCreated();
  }

  /**
   * A REFUSE entry from hashes.txt stops the slice before any write.
   */
  public function testRefuseEntryStopsTheWholeSlice(): void {
    $this->configApplier->apply(__DIR__ . '/../../../fixtures/config', ['node.type.kit_page']);
    $type = NodeType::load('kit_page');
    $this->assertInstanceOf(NodeType::class, $type);
    $type->set('name', 'Changed by a production editor')->save();

    $dir = $this->slice($this->names(), [
      'names.txt' => implode("\n", $this->names()),
      'update.txt' => "node.type.kit_page\n",
      'hashes.txt' => "# captured before the editor's change\nnode.type.kit_page=" . str_repeat('0', 64) . "\n",
    ]);

    try {
      $this->configApplier->applySlice($dir);
      $this->fail('applySlice() did not throw on a REFUSE entry.');
    }
    catch (UpdateException $e) {
      $this->assertStringContainsString('node.type.kit_page', $e->getMessage());
    }
    $this->assertNull(FieldStorageConfig::loadByName('node', 'field_kit_teaser'));
    $reloaded = NodeType::load('kit_page');
    $this->assertInstanceOf(NodeType::class, $reloaded);
    $this->assertSame('Changed by a production editor', $reloaded->label());
  }

  /**
   * Update.txt with a matching hash updates the existing object.
   */
  public function testUpdateListWithMatchingHashUpdates(): void {
    $dir = $this->slice($this->names(), ['names.txt' => "node.type.kit_page\n"]);
    $this->configApplier->applySlice($dir);
    $hash = $this->configApplier->activeHash('node.type.kit_page');
    $this->assertNotNull($hash);

    $storage = new FileStorage($dir);
    $data = $storage->read('node.type.kit_page');
    $this->assertIsArray($data);
    $data['name'] = 'Renamed in the slice';
    $storage->write('node.type.kit_page', $data);
    file_put_contents("$dir/update.txt", "node.type.kit_page\n");
    file_put_contents("$dir/hashes.txt", "node.type.kit_page=$hash\n");

    $plan = $this->configApplier->applySlice($dir);

    $this->assertSame(ConfigApplier::ACTION_UPDATE, $plan[0]['action']);
    $reloaded = NodeType::load('kit_page');
    $this->assertInstanceOf(NodeType::class, $reloaded);
    $this->assertSame('Renamed in the slice', $reloaded->label());
  }

  /**
   * A config whose module is not enabled is an ERROR in the plan.
   *
   * Without the check, the save throws halfway through apply() and leaves
   * the entries before it written.
   */
  public function testModuleDependencyNotEnabledIsAnError(): void {
    $dir = $this->slice($this->names(), ['names.txt' => implode("\n", $this->names())]);
    $storage = new FileStorage($dir);
    $data = $storage->read('field.field.node.kit_page.field_kit_teaser');
    $this->assertIsArray($data);
    $data['dependencies']['module'][] = 'kit_module_that_is_off';
    $storage->write('field.field.node.kit_page.field_kit_teaser', $data);

    $plan = $this->configApplier->plan($dir, $this->names());
    $errors = array_values(array_filter($plan, static fn (array $entry): bool => $entry['action'] === ConfigApplier::ACTION_ERROR));
    $this->assertCount(1, $errors);
    $this->assertSame('field.field.node.kit_page.field_kit_teaser', $errors[0]['name']);
    $this->assertStringContainsString('module not enabled: kit_module_that_is_off', $errors[0]['reason']);

    $this->expectException(UpdateException::class);
    try {
      $this->configApplier->applySlice($dir);
    }
    finally {
      $this->assertNothingCreated();
    }
  }

  /**
   * PlanSlice() returns the plan and writes nothing, even with errors.
   */
  public function testPlanSliceWritesNothing(): void {
    $dir = $this->slice($this->names(), [
      'names.txt' => "node.type.kit_page\nnode.type.not_in_the_slice\n",
    ]);

    $plan = $this->configApplier->planSlice($dir);

    $this->assertSame(
      [
        'node.type.not_in_the_slice' => ConfigApplier::ACTION_ERROR,
        'node.type.kit_page' => ConfigApplier::ACTION_CREATE,
      ],
      array_column($plan, 'action', 'name'),
    );
    $this->assertNothingCreated();
  }

  /**
   * A slice without names.txt is refused.
   */
  public function testMissingNamesFileIsRefused(): void {
    $dir = $this->slice([], []);

    $this->expectException(UpdateException::class);
    $this->expectExceptionMessage('names.txt');
    $this->configApplier->applySlice($dir);
  }

  /**
   * A names.txt with only comments is refused: a slice must name something.
   */
  public function testEmptyNamesFileIsRefused(): void {
    $dir = $this->slice([], ['names.txt' => "# nothing yet\n"]);

    $this->expectException(UpdateException::class);
    $this->expectExceptionMessage('names no config');
    $this->configApplier->applySlice($dir);
  }

  /**
   * A malformed hashes.txt line is refused before any write.
   */
  public function testMalformedHashLineIsRefused(): void {
    $dir = $this->slice($this->names(), [
      'names.txt' => implode("\n", $this->names()),
      'hashes.txt' => "node.type.kit_page\n",
    ]);

    try {
      $this->configApplier->applySlice($dir);
      $this->fail('applySlice() accepted a hashes.txt line without a hash.');
    }
    catch (UpdateException $e) {
      $this->assertStringContainsString('hashes.txt', $e->getMessage());
    }
    $this->assertNothingCreated();
  }

}
