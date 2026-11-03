<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\Services;

use Drupal\Component\Serialization\Exception\InvalidDataTypeException;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\Entity\ConfigDependencyManager;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Utility\UpdateException;

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
 *
 * apply() writes every CREATE and UPDATE entry of its plan, even when the
 * same plan holds an ERROR or REFUSE entry. applySlice() is the
 * all-or-nothing front end: it plans the whole slice and writes nothing
 * unless every entry is clean.
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
    protected readonly ModuleHandlerInterface $moduleHandler,
    protected readonly ThemeHandlerInterface $themeHandler,
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
          $plan[] = [
            'name' => $name,
            'action' => self::ACTION_SKIP_EXISTS,
            'reason' => 'already exists, not in --update list',
          ];
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
   * @param string $configDir
   *   Directory of exported config to read from.
   * @param string[] $names
   *   Explicit config object names to consider.
   * @param string[] $updateAllowed
   *   Names allowed to move from CREATE-only reasoning into UPDATE.
   * @param array<string, string> $expectedHashes
   *   Optional name => sha256 hash of the active value, guarding updates.
   * @param bool $dryRun
   *   TRUE to return the plan without applying it.
   *
   * @return array<int, array{name: string, action: string, reason: string}>
   *   The plan that was executed (or would have been, for a dry run).
   */
  public function apply(
    string $configDir,
    array $names,
    array $updateAllowed = [],
    array $expectedHashes = [],
    bool $dryRun = FALSE,
  ): array {
    $plan = $this->plan($configDir, $names, $updateAllowed, $expectedHashes);
    if ($dryRun) {
      return $plan;
    }

    $this->applyPlan($configDir, $plan);
    return $plan;
  }

  /**
   * Plans a slice directory without changing anything.
   *
   * A slice is a directory that holds the exported config files and a
   * `slice.yml` with these keys:
   * - `names` (required): the config names to apply;
   * - `update` (optional): names that may be updated when they exist;
   * - `hashes` (optional): name => sha256, the expected hash of the active
   *   value of an `update` name (see activeHash()).
   * The manifest is YAML, not a list of .txt files, because Drupal's
   * .htaccess denies .yml and serves .txt: a hashes file would publish the
   * hashes of the site's active config.
   *
   * On top of plan(), each CREATE or UPDATE entry whose owner or
   * dependency extension is not enabled becomes ERROR (see
   * missingExtension()).
   *
   * @param string $sliceDir
   *   The slice directory.
   *
   * @return array<int, array{name: string, action: string, reason: string}>
   *   The plan, as plan() returns it.
   *
   * @throws \Drupal\Core\Utility\UpdateException
   *   When slice.yml is missing or malformed (see readSlice()).
   */
  public function planSlice(string $sliceDir): array {
    [$names, $updateAllowed, $expectedHashes] = $this->readSlice($sliceDir);
    $plan = $this->plan($sliceDir, $names, $updateAllowed, $expectedHashes);

    // Extension preflight. It runs for slices only, so plan() and apply()
    // keep the behaviour their callers rely on.
    $source = new FileStorage($sliceDir);
    foreach ($plan as $index => $entry) {
      if (!in_array($entry['action'], [self::ACTION_CREATE, self::ACTION_UPDATE], TRUE)) {
        continue;
      }
      $missing = $this->missingExtension($entry['name'], $source->read($entry['name']) ?: []);
      if ($missing !== NULL) {
        $plan[$index] = ['name' => $entry['name'], 'action' => self::ACTION_ERROR, 'reason' => $missing];
      }
    }
    return $plan;
  }

  /**
   * Names the extension a config needs that is not enabled, if any.
   *
   * The owner is the first segment of the name (`node.type.x` belongs to
   * node), the rule core's config import uses. A config entity often does
   * not list its provider in `dependencies.module`; when the provider is
   * off, the entity type is unknown and the object would be saved as simple
   * config. `dependencies.module` and `dependencies.theme` are checked too,
   * because a save of such a config throws halfway through the slice.
   *
   * @param string $name
   *   The config name.
   * @param array<string, mixed> $data
   *   The decoded config from the slice.
   *
   * @return string|null
   *   The ERROR reason, or NULL when every extension is enabled.
   */
  protected function missingExtension(string $name, array $data): ?string {
    $owner = strstr($name, '.', TRUE) ?: $name;
    if ($owner !== 'core' && !$this->moduleHandler->moduleExists($owner) && !$this->themeHandler->themeExists($owner)) {
      return "owner not enabled: $owner";
    }
    foreach ($data['dependencies']['module'] ?? [] as $module) {
      if ($module !== 'core' && !$this->moduleHandler->moduleExists($module)) {
        return "module not enabled: $module";
      }
    }
    foreach ($data['dependencies']['theme'] ?? [] as $theme) {
      if (!$this->themeHandler->themeExists($theme)) {
        return "theme not enabled: $theme";
      }
    }
    return NULL;
  }

  /**
   * Plans a slice and applies it only when every entry is clean.
   *
   * Made for hook_post_update_NAME(): one call replaces the dry run, the
   * check and the apply each project wrote by hand. An ERROR or REFUSE
   * entry anywhere in the plan throws before the first write, so `drush
   * updb` stops with the site unchanged and runs the update again next time.
   *
   * A write can still fail after the plan, e.g. when the database refuses a
   * field table. Creating a table is not transactional, so the entries
   * before it stay written; the plan covers every failure it can foresee.
   *
   * @param string $sliceDir
   *   The slice directory (see planSlice() for the layout).
   *
   * @return array<int, array{name: string, action: string, reason: string}>
   *   The plan that was applied.
   *
   * @throws \Drupal\Core\Utility\UpdateException
   *   When the slice is malformed or any entry is ERROR or REFUSE.
   */
  public function applySlice(string $sliceDir): array {
    $plan = $this->planSlice($sliceDir);

    $problems = [];
    foreach ($plan as $entry) {
      if (in_array($entry['action'], [self::ACTION_ERROR, self::ACTION_REFUSE], TRUE)) {
        $problems[] = "{$entry['name']}: {$entry['action']} ({$entry['reason']})";
      }
    }
    if ($problems !== []) {
      throw new UpdateException("Slice $sliceDir: " . implode('; ', $problems) . '. Nothing changed.');
    }

    $this->applyPlan($sliceDir, $plan);
    return $plan;
  }

  /**
   * Writes every CREATE and UPDATE entry of a plan.
   *
   * @param string $configDir
   *   Directory the plan was built from.
   * @param array<int, array{name: string, action: string, reason: string}> $plan
   *   The plan.
   */
  protected function applyPlan(string $configDir, array $plan): void {
    $source = new FileStorage($configDir);
    foreach ($plan as $entry) {
      if (!in_array($entry['action'], [self::ACTION_CREATE, self::ACTION_UPDATE], TRUE)) {
        continue;
      }
      $this->applyOne($source, $entry['name'], $entry['action']);
    }
  }

  /**
   * Reads and validates the slice.yml of a slice.
   *
   * @return array{0: string[], 1: string[], 2: array<string, string>}
   *   The names, the update list and the expected hashes.
   *
   * @throws \Drupal\Core\Utility\UpdateException
   *   When slice.yml is missing, not a YAML map, has an unknown key or names
   *   nothing; when a list repeats a name, `update` holds a name `names`
   *   does not, or a hash is not a sha256 of an `update` name; or when a
   *   list file of the old .txt layout lies in the slice.
   */
  protected function readSlice(string $sliceDir): array {
    $refuse = static fn (string $problem): UpdateException => new UpdateException("Slice $sliceDir: $problem. Nothing changed.");

    // The old layout kept the lists in .txt files, which the web server
    // serves. Refuse them, so a slice cannot publish its hashes by accident.
    foreach (['names.txt', 'update.txt', 'hashes.txt'] as $legacy) {
      if (is_file("$sliceDir/$legacy")) {
        throw $refuse("$legacy is web readable; move its content into slice.yml and delete it");
      }
    }

    if (!is_file("$sliceDir/slice.yml")) {
      throw $refuse('has no slice.yml');
    }
    try {
      $manifest = Yaml::decode((string) file_get_contents("$sliceDir/slice.yml"));
    }
    catch (InvalidDataTypeException $e) {
      throw $refuse('slice.yml is not valid YAML (' . $e->getMessage() . ')');
    }
    if (!is_array($manifest) || ($manifest !== [] && array_is_list($manifest))) {
      throw $refuse('slice.yml must be a map with the keys names, update and hashes');
    }
    foreach (array_diff(array_keys($manifest), ['names', 'update', 'hashes']) as $key) {
      throw $refuse("slice.yml has an unknown key: $key");
    }

    $names = $this->readNames($manifest, 'names', $refuse);
    if ($names === []) {
      throw $refuse('slice.yml names lists no config');
    }
    $update = $this->readNames($manifest, 'update', $refuse);
    foreach (array_diff($update, $names) as $name) {
      throw $refuse("slice.yml update names $name, which names does not");
    }

    // A typo in a hash name would turn the guard off without a word, so
    // every hash must belong to an update name and be a sha256. An empty
    // `[]` and `{}` decode to the same PHP array, so both are accepted.
    $hashes = $manifest['hashes'] ?? [];
    if (!is_array($hashes) || ($hashes !== [] && array_is_list($hashes))) {
      throw $refuse('slice.yml hashes must map a config name to a sha256 hash');
    }
    foreach ($hashes as $name => $hash) {
      if (!in_array($name, $update, TRUE)) {
        throw $refuse("slice.yml hashes names $name, which update does not");
      }
      if (!is_string($hash) || !preg_match('/^[0-9a-f]{64}$/', $hash)) {
        throw $refuse("slice.yml hash of $name does not hold a sha256 hash");
      }
    }

    return [$names, $update, $hashes];
  }

  /**
   * Reads one list of config names from the manifest.
   *
   * @param array<string, mixed> $manifest
   *   The decoded slice.yml.
   * @param string $key
   *   The key to read.
   * @param \Closure(string): \Drupal\Core\Utility\UpdateException $refuse
   *   Builds the exception for a problem.
   *
   * @return string[]
   *   The names, or [] when the key is absent.
   */
  protected function readNames(array $manifest, string $key, \Closure $refuse): array {
    $list = $manifest[$key] ?? [];
    if (!is_array($list) || !array_is_list($list)) {
      throw $refuse("slice.yml $key must be a list of config names");
    }
    $names = [];
    foreach ($list as $name) {
      if (!is_string($name) || trim($name) === '') {
        throw $refuse("slice.yml $key must be a list of config names");
      }
      if (in_array(trim($name), $names, TRUE)) {
        throw $refuse("slice.yml $key repeats " . trim($name));
      }
      $names[] = trim($name);
    }
    return $names;
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
    if (!$storage instanceof ConfigEntityStorageInterface) {
      throw new \RuntimeException("Entity type $entityType is not config entity storage.");
    }

    if ($action === self::ACTION_CREATE) {
      $entity = $storage->createFromStorageRecord($record);
      $entity->save();
      return;
    }

    $entity = $this->configManager->loadConfigEntityByName($name);
    if (!$entity instanceof ConfigEntityInterface) {
      throw new \RuntimeException("Config entity $name disappeared between planning and apply.");
    }
    $entity = $storage->updateFromStorageRecord($entity, $record);
    $entity->save();
  }

  /**
   * Hashes a decoded config value the same way for guard and lookup.
   *
   * Exposed so callers building an $expectedHashes map compute it
   * identically to the internal guard.
   *
   * @param array<string, mixed> $data
   *   The decoded config value to hash.
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
