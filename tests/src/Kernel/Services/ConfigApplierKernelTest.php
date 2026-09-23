<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Kernel\Services;

use Drupal\Core\Config\FileStorage;
use Drupal\KernelTests\KernelTestBase;
use Drupal\drupal_kit\Services\ConfigApplier;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;

/**
 * Kernel tests for ConfigApplier / `drush kit:config-apply`.
 *
 * Fixture config lives in tests/fixtures/config/ as plain YAML exports:
 * a node bundle ("kit_page"), a field.storage.node.field_kit_teaser and
 * its field.field.node.kit_page.field_kit_teaser — enough to exercise
 * dependency order (bundle -> storage -> field) without pulling in the
 * paragraphs module as a test dependency.
 *
 * @group drupal_kit
 */
final class ConfigApplierKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['drupal_kit', 'system', 'user', 'field', 'text', 'node'];

  protected ConfigApplier $configApplier;

  protected string $fixtureDir;

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
    $this->fixtureDir = __DIR__ . '/../../../fixtures/config';
  }

  /**
   * @return string[]
   */
  private function names(): array {
    return [
      'node.type.kit_page',
      'field.storage.node.field_kit_teaser',
      'field.field.node.kit_page.field_kit_teaser',
    ];
  }

  public function testCreateInDependencyOrder(): void {
    $plan = $this->configApplier->apply($this->fixtureDir, $this->names());

    $this->assertSame(
      ['node.type.kit_page', 'field.storage.node.field_kit_teaser', 'field.field.node.kit_page.field_kit_teaser'],
      array_column($plan, 'name'),
    );
    foreach ($plan as $entry) {
      $this->assertSame(ConfigApplier::ACTION_CREATE, $entry['action']);
    }

    $this->assertNotNull(NodeType::load('kit_page'));
    $this->assertNotNull(FieldStorageConfig::loadByName('node', 'field_kit_teaser'));
    $this->assertNotNull(FieldConfig::loadByName('node', 'kit_page', 'field_kit_teaser'));

    // The field storage table exists and is usable — the point of going
    // through the entity API instead of raw config save().
    $node = \Drupal\node\Entity\Node::create([
      'type' => 'kit_page',
      'title' => 'Hi',
      'field_kit_teaser' => 'Teaser text',
    ]);
    $node->save();
    $this->assertSame('Teaser text', $node->get('field_kit_teaser')->value);
  }

  public function testIdempotentReRunSkipsExisting(): void {
    $this->configApplier->apply($this->fixtureDir, $this->names());
    $plan = $this->configApplier->apply($this->fixtureDir, $this->names());

    foreach ($plan as $entry) {
      $this->assertSame(ConfigApplier::ACTION_SKIP_EXISTS, $entry['action']);
    }
  }

  public function testCreateOnlyModeSkipsWithoutUpdateList(): void {
    $this->configApplier->apply($this->fixtureDir, ['node.type.kit_page']);

    $plan = $this->configApplier->plan($this->fixtureDir, ['node.type.kit_page']);
    $this->assertSame(ConfigApplier::ACTION_SKIP_EXISTS, $plan[0]['action']);

    // Confirm nothing changed: label in fixture differs from what we'll
    // set on the active entity below, and it should stay put.
    $type = NodeType::load('kit_page');
    $this->assertInstanceOf(NodeType::class, $type);
    $type->set('name', 'Changed locally')->save();

    $this->configApplier->apply($this->fixtureDir, ['node.type.kit_page']);
    $reloaded = NodeType::load('kit_page');
    $this->assertInstanceOf(NodeType::class, $reloaded);
    $this->assertSame('Changed locally', $reloaded->label());
  }

  public function testUpdateGuardRefusesOnHashMismatch(): void {
    $this->configApplier->apply($this->fixtureDir, ['node.type.kit_page']);

    $type = NodeType::load('kit_page');
    $this->assertInstanceOf(NodeType::class, $type);
    $type->set('name', 'Changed by a production editor')->save();

    $staleHash = hash('sha256', serialize(['name' => 'Kit Page', 'type' => 'kit_page']));

    $plan = $this->configApplier->apply(
      $this->fixtureDir,
      ['node.type.kit_page'],
      updateAllowed: ['node.type.kit_page'],
      expectedHashes: ['node.type.kit_page' => $staleHash],
    );

    $this->assertSame(ConfigApplier::ACTION_REFUSE, $plan[0]['action']);
    $reloaded = NodeType::load('kit_page');
    $this->assertInstanceOf(NodeType::class, $reloaded);
    $this->assertSame('Changed by a production editor', $reloaded->label());
  }

  public function testUpdateAppliesWhenHashMatchesActiveValue(): void {
    $this->configApplier->apply($this->fixtureDir, ['node.type.kit_page']);

    $activeHash = $this->configApplier->activeHash('node.type.kit_page');
    $this->assertNotNull($activeHash);

    // Fixture on disk now describes a renamed label.
    $source = new FileStorage($this->fixtureDir);
    $updatedFixtureDir = $this->getRandomGenerator()->name();
    $updatedFixtureDir = sys_get_temp_dir() . '/' . $updatedFixtureDir;
    mkdir($updatedFixtureDir);
    $target = new FileStorage($updatedFixtureDir);
    foreach (['node.type.kit_page', 'field.storage.node.field_kit_teaser', 'field.field.node.kit_page.field_kit_teaser'] as $name) {
      $record = $source->read($name);
      $this->assertIsArray($record);
      $target->write($name, $record);
    }
    $data = $target->read('node.type.kit_page');
    $this->assertIsArray($data);
    $data['name'] = 'Renamed Upstream';
    $target->write('node.type.kit_page', $data);

    $plan = $this->configApplier->apply(
      $updatedFixtureDir,
      ['node.type.kit_page'],
      updateAllowed: ['node.type.kit_page'],
      expectedHashes: ['node.type.kit_page' => $activeHash],
    );

    $this->assertSame(ConfigApplier::ACTION_UPDATE, $plan[0]['action']);
    $reloaded = NodeType::load('kit_page');
    $this->assertInstanceOf(NodeType::class, $reloaded);
    $this->assertSame('Renamed Upstream', $reloaded->label());
  }

  public function testMissingDependencyIsReportedAsError(): void {
    $plan = $this->configApplier->plan($this->fixtureDir, ['field.field.node.kit_page.field_kit_teaser']);

    $this->assertSame(ConfigApplier::ACTION_ERROR, $plan[0]['action']);
    $this->assertStringContainsString('missing dependency', $plan[0]['reason']);
    $this->assertNull(FieldConfig::loadByName('node', 'kit_page', 'field_kit_teaser'));
  }

  public function testDryRunChangesNothing(): void {
    $plan = $this->configApplier->apply($this->fixtureDir, $this->names(), dryRun: TRUE);

    $this->assertSame(ConfigApplier::ACTION_CREATE, $plan[0]['action']);
    $this->assertNull(NodeType::load('kit_page'));
  }

}
