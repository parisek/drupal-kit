<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Kernel\Services;

use Drupal\Component\Serialization\Yaml;
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
 * A slice is a directory with the config files and a slice.yml that names
 * them (`names`, optional `update` and `hashes`). applySlice() plans the
 * whole slice first and writes nothing when one entry is ERROR or REFUSE.
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
   * @param array<string, mixed>|string|null $manifest
   *   The slice.yml content: an array to encode, a raw string, or NULL for
   *   no slice.yml at all.
   *
   * @return string
   *   The slice directory.
   */
  private function slice(array $names, array|string|null $manifest): string {
    $dir = sys_get_temp_dir() . '/' . $this->getRandomGenerator()->name();
    mkdir($dir);
    $target = new FileStorage($dir);
    foreach ($names as $name) {
      $record = $this->fixtures->read($name);
      $this->assertIsArray($record);
      $target->write($name, $record);
    }
    if ($manifest !== NULL) {
      file_put_contents("$dir/slice.yml", is_array($manifest) ? Yaml::encode($manifest) : $manifest);
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
   * Asserts that applySlice() throws before any write.
   *
   * @param string $dir
   *   The slice directory.
   * @param string $message
   *   A fragment of the expected message.
   */
  private function assertRefused(string $dir, string $message): void {
    try {
      $this->configApplier->applySlice($dir);
      $this->fail('applySlice() did not throw.');
    }
    catch (UpdateException $e) {
      $this->assertStringContainsString($message, $e->getMessage());
      $this->assertStringContainsString('Nothing changed', $e->getMessage());
    }
    $this->assertNothingCreated();
  }

  /**
   * The names in slice.yml drive the slice, in any order.
   */
  public function testApplySliceCreatesEveryName(): void {
    $dir = $this->slice($this->names(), "# Kit page\nnames:\n  - field.field.node.kit_page.field_kit_teaser\n  - node.type.kit_page\n  - field.storage.node.field_kit_teaser\n");

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
    $dir = $this->slice($this->names(), ['names' => $this->names()]);

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
      'names' => ['node.type.kit_page', 'field.storage.node.field_kit_teaser', 'node.type.not_in_the_slice'],
    ]);

    $this->assertRefused($dir, 'node.type.not_in_the_slice');
  }

  /**
   * A REFUSE entry from the hashes stops the slice before any write.
   */
  public function testRefuseEntryStopsTheWholeSlice(): void {
    $this->configApplier->apply(__DIR__ . '/../../../fixtures/config', ['node.type.kit_page']);
    $type = NodeType::load('kit_page');
    $this->assertInstanceOf(NodeType::class, $type);
    $type->set('name', 'Changed by a production editor')->save();

    $dir = $this->slice($this->names(), [
      'names' => $this->names(),
      'update' => ['node.type.kit_page'],
      'hashes' => ['node.type.kit_page' => str_repeat('0', 64)],
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
   * An update entry with a matching hash updates the existing object.
   */
  public function testUpdateListWithMatchingHashUpdates(): void {
    $dir = $this->slice($this->names(), ['names' => ['node.type.kit_page']]);
    $this->configApplier->applySlice($dir);
    $hash = $this->configApplier->activeHash('node.type.kit_page');
    $this->assertNotNull($hash);

    $storage = new FileStorage($dir);
    $data = $storage->read('node.type.kit_page');
    $this->assertIsArray($data);
    $data['name'] = 'Renamed in the slice';
    $storage->write('node.type.kit_page', $data);
    file_put_contents("$dir/slice.yml", Yaml::encode([
      'names' => ['node.type.kit_page'],
      'update' => ['node.type.kit_page'],
      'hashes' => ['node.type.kit_page' => $hash],
    ]));

    $plan = $this->configApplier->applySlice($dir);

    $this->assertSame(ConfigApplier::ACTION_UPDATE, $plan[0]['action']);
    $reloaded = NodeType::load('kit_page');
    $this->assertInstanceOf(NodeType::class, $reloaded);
    $this->assertSame('Renamed in the slice', $reloaded->label());
  }

  /**
   * A config whose module is not enabled is an ERROR in the slice plan.
   *
   * Without the check, the save throws halfway through the slice and leaves
   * the entries before it written.
   */
  public function testModuleDependencyNotEnabledIsAnError(): void {
    $dir = $this->slice($this->names(), ['names' => $this->names()]);
    $storage = new FileStorage($dir);
    $data = $storage->read('field.field.node.kit_page.field_kit_teaser');
    $this->assertIsArray($data);
    $data['dependencies']['module'][] = 'kit_module_that_is_off';
    $storage->write('field.field.node.kit_page.field_kit_teaser', $data);

    $plan = $this->configApplier->planSlice($dir);
    $errors = array_values(array_filter($plan, static fn (array $entry): bool => $entry['action'] === ConfigApplier::ACTION_ERROR));
    $this->assertCount(1, $errors);
    $this->assertSame('field.field.node.kit_page.field_kit_teaser', $errors[0]['name']);
    $this->assertSame('module not enabled: kit_module_that_is_off', $errors[0]['reason']);

    // plan() and apply() keep their behaviour: the check is slice-only.
    $legacy = $this->configApplier->plan($dir, $this->names());
    $this->assertSame(
      [ConfigApplier::ACTION_CREATE, ConfigApplier::ACTION_CREATE, ConfigApplier::ACTION_CREATE],
      array_column($legacy, 'action'),
    );

    $this->assertRefused($dir, 'kit_module_that_is_off');
  }

  /**
   * A config whose owner is off is an ERROR, even with no dependencies.
   *
   * A config entity often leaves its provider out of dependencies.module.
   * With the provider off, the entity type is unknown and the object would
   * be saved as simple config.
   */
  public function testOwnerNotEnabledIsAnError(): void {
    $dir = $this->slice($this->names(), ['names' => [...$this->names(), 'kit_off_module.thing.one']]);
    (new FileStorage($dir))->write('kit_off_module.thing.one', ['id' => 'one']);

    $plan = array_column($this->configApplier->planSlice($dir), 'reason', 'name');
    $this->assertSame('owner not enabled: kit_off_module', $plan['kit_off_module.thing.one']);

    $this->assertRefused($dir, 'kit_off_module');
    $this->assertTrue($this->container->get('config.factory')->get('kit_off_module.thing.one')->isNew());
  }

  /**
   * A broken slice.yml is refused before any write.
   *
   * @param array<string, mixed>|string|null $manifest
   *   The slice.yml content, as slice() takes it.
   * @param string $message
   *   A fragment of the expected message.
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('brokenManifestProvider')]
  public function testBrokenManifestIsRefused(array|string|null $manifest, string $message): void {
    $dir = $this->slice($this->names(), $manifest);

    $this->assertRefused($dir, $message);
  }

  /**
   * Cases for testBrokenManifestIsRefused().
   *
   * @return array<string, array{0: array<string, mixed>|string|null, 1: string}>
   *   The slice.yml content and the expected message fragment.
   */
  public static function brokenManifestProvider(): array {
    $names = [
      'node.type.kit_page',
      'field.storage.node.field_kit_teaser',
      'field.field.node.kit_page.field_kit_teaser',
    ];
    $hash = str_repeat('a', 64);
    return [
      'no slice.yml' => [NULL, 'has no slice.yml'],
      'not YAML' => ["names: [unclosed\n", 'slice.yml is not valid YAML'],
      'not a map' => ["- node.type.kit_page\n", 'slice.yml must be a map'],
      'no names' => [['update' => []], 'names lists no config'],
      'empty names' => [['names' => []], 'names lists no config'],
      'names not a list' => [['names' => 'node.type.kit_page'], 'names must be a list of config names'],
      'unknown key' => [['names' => $names, 'updates' => ['node.type.kit_page']], 'unknown key: updates'],
      'repeated name' => [['names' => [...$names, 'node.type.kit_page']], 'names repeats node.type.kit_page'],
      'update entry not in names' => [
        ['names' => $names, 'update' => ['node.type.other']],
        'update names node.type.other',
      ],
      'hashes not a map' => [
        ['names' => $names, 'update' => ['node.type.kit_page'], 'hashes' => [$hash]],
        'hashes must map a config name to a sha256 hash',
      ],
      'hash without update entry' => [
        ['names' => $names, 'update' => ['node.type.kit_page'], 'hashes' => ['node.type.kit_pag' => $hash]],
        'which update does not',
      ],
      'short hash' => [
        ['names' => $names, 'update' => ['node.type.kit_page'], 'hashes' => ['node.type.kit_page' => 'abc']],
        'does not hold a sha256 hash',
      ],
    ];
  }

  /**
   * PlanSlice() returns the plan and writes nothing, even with errors.
   */
  public function testPlanSliceWritesNothing(): void {
    $dir = $this->slice($this->names(), ['names' => ['node.type.kit_page', 'node.type.not_in_the_slice']]);

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
   * A list file from the old layout is refused: it is web readable.
   *
   * Core's .htaccess denies .yml but serves .txt, so a names.txt beside the
   * slice would publish its config names and a hashes.txt the active hashes.
   */
  public function testLegacyListFileIsRefused(): void {
    $dir = $this->slice($this->names(), ['names' => $this->names()]);
    file_put_contents("$dir/hashes.txt", 'node.type.kit_page=' . str_repeat('a', 64) . "\n");

    $this->assertRefused($dir, 'hashes.txt');
  }

}
