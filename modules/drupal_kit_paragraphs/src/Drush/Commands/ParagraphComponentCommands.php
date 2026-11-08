<?php

declare(strict_types=1);

namespace Drupal\drupal_kit_paragraphs\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\DrupalKernelInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\drupal_kit_paragraphs\Display\ParagraphDisplaySwitcher;
use Drupal\drupal_kit_paragraphs\Mapper\ComponentFileSourceChecker;
use Drupal\drupal_kit_paragraphs\Mapper\ParagraphComponentMapperInterface;
use Drupal\paragraphs\ParagraphInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush commands for the paragraph component mapper.
 *
 * - `drupal-kit:paragraph-map` explains how one paragraph is mapped.
 * - `drupal-kit:paragraph-switch` moves a bundle between the legacy display
 *   and the component, and back.
 * - `drupal-kit:lint-components` checks component PHP files, for CI.
 */
final class ParagraphComponentCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Folders that the lint command never enters.
   */
  private const SKIP_DIRECTORIES = ['node_modules', 'vendor', '.git'];

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'drupal_kit.paragraph_component_mapper')]
    protected readonly ParagraphComponentMapperInterface $mapper,
    #[Autowire(service: 'drupal_kit.paragraph_display_switcher')]
    protected readonly ParagraphDisplaySwitcher $switcher,
    #[Autowire(service: 'kernel')]
    protected readonly DrupalKernelInterface $kernel,
  ) {
    parent::__construct();
  }

  /**
   * Explains how one paragraph is mapped to its component.
   *
   * Prints the component, the files that took part, the content keys, the step
   * that wrote each key (framework, definition, file replace, file alter,
   * module hook, theme hook) and the cache metadata. It changes nothing.
   *
   * @param int $id
   *   The paragraph id.
   * @param array<string, mixed> $options
   *   Drush command options, keyed by option name.
   */
  #[CLI\Command(name: 'drupal-kit:paragraph-map', aliases: ['dkpm'])]
  #[CLI\Argument(name: 'id', description: 'The paragraph id.')]
  #[CLI\Option(name: 'view-mode', description: 'The view mode to map for.')]
  #[CLI\Option(name: 'langcode', description: 'Map the translation in this language.')]
  #[CLI\Usage(name: 'drush drupal-kit:paragraph-map 123 --format=json', description: 'Show where each content key of paragraph 123 comes from.')]
  public function paragraphMap(int $id, array $options = ['view-mode' => 'default', 'langcode' => '']): PropertyList {
    $paragraph = $this->entityTypeManager->getStorage('paragraph')->load($id);
    if (!$paragraph instanceof ParagraphInterface) {
      throw new \InvalidArgumentException(sprintf('Paragraph %d does not exist.', $id));
    }
    $langcode = (string) $options['langcode'];
    if ($langcode !== '') {
      if (!$paragraph->hasTranslation($langcode)) {
        throw new \InvalidArgumentException(sprintf('Paragraph %d has no %s translation.', $id, $langcode));
      }
      $paragraph = $paragraph->getTranslation($langcode);
    }

    $view_mode = (string) $options['view-mode'];
    $mapping = $this->mapper->map($paragraph, $view_mode);
    $cache = $mapping->getCacheableMetadata();

    return new PropertyList([
      'paragraph' => $paragraph->id(),
      'bundle' => $paragraph->bundle(),
      'langcode' => $paragraph->language()->getId(),
      'view_mode' => $view_mode,
      'component' => $mapping->getComponent(),
      'template' => $mapping->getTemplate(),
      'definition_file' => $mapping->getSources()['definition'] ?? NULL,
      'component_php_file' => $mapping->getSources()['file'] ?? NULL,
      'origins' => $mapping->getOrigins(),
      'diagnostics' => $mapping->getDiagnostics(),
      'cache_tags' => $cache->getCacheTags(),
      'cache_contexts' => $cache->getCacheContexts(),
      'cache_max_age' => $cache->getCacheMaxAge(),
      'content' => $this->export($mapping->getData()),
    ]);
  }

  /**
   * Moves a paragraph bundle between the legacy display and the component.
   *
   * Swaps the extra field on the view display. The legacy settings are kept in
   * State, so `legacy` restores them. No release and no config import is
   * needed. Render caches clear through the display's cache tags.
   *
   * @param string $bundle
   *   The paragraph bundle.
   * @param string $target
   *   Either `component` or `legacy`.
   * @param array<string, mixed> $options
   *   Drush command options, keyed by option name.
   */
  #[CLI\Command(name: 'drupal-kit:paragraph-switch', aliases: ['dkps'])]
  #[CLI\Argument(name: 'bundle', description: 'The paragraph bundle.')]
  #[CLI\Argument(name: 'target', description: '"component" or "legacy".')]
  #[CLI\Option(name: 'view-mode', description: 'The view mode of the display.')]
  #[CLI\Option(name: 'legacy-field', description: 'The legacy extra field on the display.')]
  #[CLI\Option(name: 'dry-run', description: 'Print the plan and save nothing.')]
  #[CLI\Usage(name: 'drush drupal-kit:paragraph-switch contact component', description: 'Render the contact bundle from its component definition.')]
  #[CLI\Usage(name: 'drush drupal-kit:paragraph-switch contact legacy', description: 'Roll the contact bundle back to its legacy display.')]
  public function paragraphSwitch(
    string $bundle,
    string $target,
    array $options = [
      'view-mode' => 'default',
      'legacy-field' => ParagraphDisplaySwitcher::DEFAULT_LEGACY_FIELD,
      'dry-run' => FALSE,
    ],
  ): PropertyList {
    return new PropertyList($this->switcher->switch(
      $bundle,
      $target,
      (string) $options['view-mode'],
      (string) $options['legacy-field'],
      (bool) $options['dry-run'],
    ));
  }

  /**
   * Checks the component PHP files against the file rules. For CI.
   *
   * A component PHP file is `<name>/<name>.php` beside `<name>.twig` or
   * `<name>.yaml`. Each one must follow the rules of
   * ComponentFileSourceChecker.
   * The command exits with an error when a file breaks them.
   *
   * @param string[] $paths
   *   Folders to scan. The default is the themes/custom folder.
   */
  #[CLI\Command(name: 'drupal-kit:lint-components', aliases: ['dklc'])]
  #[CLI\Argument(name: 'paths', description: 'Folders to scan, relative to the Drupal root or absolute.')]
  #[CLI\Usage(name: 'drush drupal-kit:lint-components web/themes/custom', description: 'Lint every component PHP file of the custom themes.')]
  public function lintComponents(array $paths): RowsOfFields {
    $paths = $paths === [] ? ['themes/custom'] : $paths;
    $rows = [];
    $failed = 0;
    foreach ($this->findComponentFiles($paths) as $file) {
      $source = file_get_contents($file);
      $reason = $source === FALSE ? 'it cannot be read' : ComponentFileSourceChecker::check($source);
      if ($reason !== NULL) {
        $failed++;
      }
      $rows[] = ['file' => $file, 'result' => $reason === NULL ? 'ok' : 'FAIL: ' . $reason];
    }
    if ($rows === []) {
      throw new \RuntimeException('No component PHP file found. Check the paths.');
    }
    if ($failed > 0) {
      $this->io()->error(sprintf('%d of %d component PHP files break the rules.', $failed, count($rows)));
      foreach ($rows as $row) {
        if ($row['result'] !== 'ok') {
          $this->io()->writeln($row['file'] . ': ' . $row['result']);
        }
      }
      throw new \RuntimeException('Component PHP lint failed.');
    }
    return new RowsOfFields($rows);
  }

  /**
   * Finds `<name>/<name>.php` files that have a template or definition beside.
   *
   * @param string[] $paths
   *   The folders to scan.
   *
   * @return string[]
   *   The file paths, sorted.
   */
  private function findComponentFiles(array $paths): array {
    $found = [];
    foreach ($paths as $path) {
      $root = str_starts_with($path, '/') ? $path : $this->kernel->getAppRoot() . '/' . $path;
      if (!is_dir($root)) {
        throw new \InvalidArgumentException(sprintf('%s is not a folder.', $root));
      }
      $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
        new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        static fn (\SplFileInfo $item): bool => !($item->isDir() && in_array($item->getFilename(), self::SKIP_DIRECTORIES, TRUE)),
      ));
      foreach ($iterator as $item) {
        if (!$item instanceof \SplFileInfo || !$item->isFile() || $item->getExtension() !== 'php') {
          continue;
        }
        $name = $item->getBasename('.php');
        $directory = $item->getPath();
        if (basename($directory) === $name && (is_file("$directory/$name.twig") || is_file("$directory/$name.yaml"))) {
          $found[] = $item->getPathname();
        }
      }
    }
    sort($found);
    return $found;
  }

  /**
   * Turns a value into something a formatter prints.
   *
   * @param mixed $value
   *   Any value of a mapping.
   * @param int $depth
   *   The recursion depth, to stop on deep or cyclic data.
   *
   * @return mixed
   *   Scalars, arrays and strings.
   */
  private function export(mixed $value, int $depth = 0): mixed {
    if (is_array($value)) {
      if ($depth >= 8) {
        return '[too deep]';
      }
      return array_map(fn ($item) => $this->export($item, $depth + 1), $value);
    }
    if (is_scalar($value) || $value === NULL) {
      return $value;
    }
    if ($value instanceof \Stringable) {
      return (string) $value;
    }
    return '[' . get_debug_type($value) . ']';
  }

}
