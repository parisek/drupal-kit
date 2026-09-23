<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\Services;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\Entity\ConfigDependencyManager;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Applies a named, explicit slice of config through the entity/config API.
 *
 * Sites on this stack never run `drush config:import` on deploy — a full
 * import would overwrite production UI edits with whatever the repository
 * happens to hold. This service is the alternative: it creates (and,
 * opt-in, updates) an explicit list of config objects, in dependency
 * order, using the same `ConfigEntityStorage::createFromStorageRecord()` /
 * `updateFromStorageRecord()` calls core's own config sync uses. That
 * matters because it is what makes a `field.storage.*` creation actually
 * create the database column and refresh the entity field map — writing
 * the raw config object with `\Drupal::configFactory()` would not.
 *
 * It never deletes anything and it never operates on "everything in the
 * directory" — the caller always supplies the exact list of config names.
 */
class ConfigApplier {

  /**
   * Config existed and was skipped (create-only mode, not in update list).
   */
  public const ACTION_SKIP_EXISTS = 'SKIP-EXISTS';

  /**
   * Config did not exist and was (or would be) created.
   */
  public const ACTION_CREATE = 'CREATE';

  /**
   * Config existed, was in the update list, and was (or would be) updated.
   */
  public const ACTION_UPDATE = 'UPDATE';

  /**
   * An update was refused because the active value's hash did not match.
   */
  public const ACTION_REFUSE = 'REFUSE';

  /**
   * The config could not be planned — missing dependency or bad fixture.
   */
  public const ACTION_ERROR = 'ERROR';

  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly ConfigManagerInterface $configManager,
    protected readonly StorageInterface $activeStorage,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Builds the plan without changing anything.
   *
   * @param string $configDir
   *   Directory of exported config (e.g. `config/sync`), read via a plain
   *   FileStorage — no relation to the active config storage.
   * @param string[] $names
   *   Explicit config object names to consider. Never "everything in
   *   $configDir" — the caller enumerates them (directly or from a list
   *   file; reading the list file is the caller's job, not this method's).
   * @param string[] $updateAllowed
   *   Names that may move from CREATE-only reasoning into UPDATE when they
   *   already exist. A name absent from both the active storage and this
   *   list is skipped, not updated, even if its fixture value differs.
   * @param array<string, string> $expectedHashes
   *   Optional name => sha256 hash of the *active* value. When set for a
   *   name in $updateAllowed, the update is refused unless the active
   *   value's hash matches — protection against overwriting a production
   *   UI edit that diverged from the repository's copy.
   *
   * @return array<int, array{name: string, action: string, reason: string}>
   *   The ordered plan. Order follows config dependency order for
   *   CREATE/UPDATE entries; ERROR entries are appended at the point their
   *   dependency check failed.
   */
  public function plan(string $configDir, array $names, array $updateAllowed = [], array $expectedHashes = []): array {
    $names = array_values(array_unique($names));
    if ($names === []) {
      return [];
    }

    $source = new FileStorage($configDir);
    $data = [];
    $plan = [];

    foreach ($names as $name) {
      if (!$source->exists($name)) {
        $plan[] = ['name' => $name, 'action' => self::ACTION_ERROR, 'reason' => "not found in $configDir"];
        continue;
      }
      $decoded = $source->read($name);
      if ($decoded === FALSE) {
        $plan[] = ['name' => $name, 'action' => self::ACTION_ERROR, 'reason' => 'could not be decoded'];
        continue;
      }
      $data[$name] = $decoded;
    }

    // Missing-dependency check: a dependency must be either in our own
    // fixture set or already present in the active store. Anything else
    // means the plan can't be trusted to apply cleanly (e.g. a
    // field.field.* whose field.storage.* was left off the list).
    foreach ($data as $name => $item) {
      foreach ($item['dependencies']['config'] ?? [] as $dependency) {
        if (!isset($data[$dependency]) && !$this->activeStorage->exists($dependency)) {
          $plan[] = [
            'name' => $name,
            'action' => self::ACTION_ERROR,
            'reason' => "missing dependency: $dependency",
          ];
          unset($data[$name]);
          continue 2;
        }
      }
    }

    if ($data !== []) {
      $dependencyManager = new ConfigDependencyManager();
      $dependencyManager->setData($data);
      $sorted = array_intersect($dependencyManager->sortAll(), array_keys($data));

      foreach ($sorted as $name) {
        $exists = $this->activeStorage->exists($name);
        if (!$exists) {
          $plan[] = ['name' => $name, 'action' => self::ACTION_CREATE, 'reason' => 'does not exist yet'];
          continue;
        }
        if (!in_array($name, $updateAllowed, TRUE)) {
          $plan[] = ['name' => $name, 'action' => self::ACTION_SKIP_EXISTS, 'reason' => 'already exists, not in --update list'];
          continue;
        }
        if (isset($expectedHashes[$name])) {
          $activeHash = $this->hash($this->activeStorage->read($name) ?: []);
          if (!hash_equals($expectedHashes[$name], $activeHash)) {
            $plan[] = [
              'name' => $name,
              'action' => self::ACTION_REFUSE,
              'reason' => "active value hash $activeHash does not match expected {$expectedHashes[$name]}",
            ];
            continue;
          }
        }
        $plan[] = ['name' => $name, 'action' => self::ACTION_UPDATE, 'reason' => 'exists, in --update list'];
      }
    }

    return $plan;
  }

  /**
   * Builds the plan and, unless $dryRun, applies every CREATE/UPDATE entry.
   *
   * Idempotent: a config already created is reported SKIP-EXISTS (or
   * UPDATE, if listed) on the next run rather than failing. Safe to call
   * from a hook_post_update_NAME() on every deploy.
   *
   * @return array<int, array{name: string, action: string, reason: string}>
   *   The plan that was executed (or would have been, for a dry run).
   */
  /**
   * @param string[] $names
   * @param string[] $updateAllowed
   * @param array<string, string> $expectedHashes
   *
   * @return array<int, array{name: string, action: string, reason: string}>
   */
  public function apply(string $configDir, array $names, array $updateAllowed = [], array $expectedHashes = [], bool $dryRun = FALSE): array {
    $plan = $this->plan($configDir, $names, $updateAllowed, $expectedHashes);
    if ($dryRun) {
      return $plan;
    }

    $source = new FileStorage($configDir);
    foreach ($plan as $entry) {
      if (!in_array($entry['action'], [self::ACTION_CREATE, self::ACTION_UPDATE], TRUE)) {
        continue;
      }
      $this->applyOne($source, $entry['name'], $entry['action']);
    }

    return $plan;
  }

  /**
   * Creates or updates a single config object through the entity API.
   */
  protected function applyOne(StorageInterface $source, string $name, string $action): void {
    $record = $source->read($name);
    if ($record === FALSE) {
      throw new \RuntimeException("Config $name disappeared between planning and apply.");
    }
    $entityType = $this->configManager->getEntityTypeIdByName($name);

    if ($entityType === NULL) {
      // Simple (non-entity) config — e.g. a module's own settings object.
      $this->configFactory->getEditable($name)->setData($record)->save();
      return;
    }

    $storage = $this->entityTypeManager->getStorage($entityType);
    if (!$storage instanceof \Drupal\Core\Config\Entity\ConfigEntityStorageInterface) {
      throw new \RuntimeException("Entity type $entityType is not config entity storage.");
    }

    if ($action === self::ACTION_CREATE) {
      $entity = $storage->createFromStorageRecord($record);
      $entity->save();
      return;
    }

    $entity = $this->configManager->loadConfigEntityByName($name);
    if (!$entity instanceof \Drupal\Core\Config\Entity\ConfigEntityInterface) {
      throw new \RuntimeException("Config entity $name disappeared between planning and apply.");
    }
    $entity = $storage->updateFromStorageRecord($entity, $record);
    $entity->save();
  }

  /**
   * Hashes a decoded config value the same way for guard and lookup.
   *
   * Exposed so `kit:config-apply --show-hash` and callers building an
   * $expectedHashes map compute it identically to the internal guard.
   *
   * @param array<string, mixed> $data
   */
  public function hash(array $data): string {
    return hash('sha256', serialize($data));
  }

  /**
   * Reads and hashes a name's *active* value, or NULL if it doesn't exist.
   */
  public function activeHash(string $name): ?string {
    if (!$this->activeStorage->exists($name)) {
      return NULL;
    }
    return $this->hash($this->activeStorage->read($name) ?: []);
  }

}
