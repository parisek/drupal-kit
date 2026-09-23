<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\drupal_kit\Services\ConfigApplier;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush command wrapping ConfigApplier: `drush kit:config-apply`.
 *
 * Discovered automatically by Drush's commandfile scan — no service
 * registration needed, per Drush 12+ convention for
 * Drupal\<module>\Commands\*Commands classes.
 */
final class ConfigApplierCommands extends DrushCommands {

  public function __construct(
    protected readonly ConfigApplier $configApplier,
  ) {
    parent::__construct();
  }

  /**
   * Creates (and optionally updates) an explicit list of config objects.
   *
   * Never touches config outside the given list, and never deletes
   * anything. This is the supported alternative to `drush config:import`
   * on this stack, where a full import would overwrite production UI
   * edits. See the ConfigApplier README section for the
   * hook_post_update_NAME() pattern this command is a manual front end
   * for.
   *
   * @param array<string, mixed> $options
   */
  #[CLI\Command(name: 'kit:config-apply', aliases: ['kca'])]
  #[CLI\Option(name: 'config-dir', description: 'Directory to read config from. Defaults to config/sync.')]
  #[CLI\Option(name: 'names', description: 'Comma-separated config object names.')]
  #[CLI\Option(name: 'names-file', description: 'Path to a file with one config name per line (# comments allowed). Combined with --names when both are given.')]
  #[CLI\Option(name: 'update', description: 'Comma-separated config names allowed to change if they already exist. Never deletes.')]
  #[CLI\Option(name: 'expect-hash', description: 'Repeatable name=hash pairs; refuses an --update entry whose active value does not hash to this. Give multiple times or comma-separate.')]
  #[CLI\Option(name: 'dry-run', description: 'Print the plan and change nothing.')]
  #[CLI\Usage(name: 'drush kit:config-apply --names=paragraphs.paragraphs_type.hero,field.storage.paragraph.field_hero_image,field.field.paragraph.hero.field_hero_image', description: 'Create a new paragraph type and its fields on deploy.')]
  #[CLI\Usage(name: 'drush kit:config-apply --names-file=../config-deploy/hero.txt --dry-run', description: 'Preview a list-file-driven apply.')]
  public function configApply(
    array $options = [
      'config-dir' => 'config/sync',
      'names' => '',
      'names-file' => '',
      'update' => '',
      'expect-hash' => [],
      'dry-run' => FALSE,
    ],
  ): RowsOfFields {
    $names = $this->splitList((string) $options['names']);

    if (!empty($options['names-file'])) {
      $names = array_merge($names, $this->readNamesFile((string) $options['names-file']));
    }

    if ($names === []) {
      throw new \InvalidArgumentException('Give at least one config name via --names or --names-file. This command never applies "everything".');
    }

    $updateAllowed = $this->splitList((string) $options['update']);
    $expectedHashes = $this->parseHashOptions($options['expect-hash']);

    $plan = $this->configApplier->apply(
      (string) $options['config-dir'],
      $names,
      $updateAllowed,
      $expectedHashes,
      (bool) $options['dry-run'],
    );

    return new RowsOfFields($plan);
  }

  /**
   * @return string[]
   */
  private function splitList(string $value): array {
    return array_values(array_filter(array_map('trim', explode(',', $value))));
  }

  /**
   * @return string[]
   */
  private function readNamesFile(string $path): array {
    if (!is_readable($path)) {
      throw new \InvalidArgumentException("Cannot read names file: $path");
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $names = [];
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line === '' || str_starts_with($line, '#')) {
        continue;
      }
      $names[] = $line;
    }
    return $names;
  }

  /**
   * @param string|array<int, string> $option
   *
   * @return array<string, string>
   */
  private function parseHashOptions(string|array $option): array {
    $pairs = is_array($option) ? $option : $this->splitList($option);
    $hashes = [];
    foreach ($pairs as $pair) {
      foreach ($this->splitList((string) $pair) as $item) {
        [$name, $hash] = array_pad(explode('=', $item, 2), 2, NULL);
        if ($name === NULL || $hash === NULL || $name === '' || $hash === '') {
          throw new \InvalidArgumentException("Malformed --expect-hash entry: $item (want name=hash)");
        }
        $hashes[$name] = $hash;
      }
    }
    return $hashes;
  }

}
