<?php

declare(strict_types=1);

namespace Drupal\drupal_kit_paragraphs\Mapper;

use Drupal\drupal_kit\Component\ComponentDefinition;
use Drupal\drupal_kit\Component\ComponentDefinitionRepositoryInterface;
use Drupal\drupal_kit\Component\InvalidComponentDefinitionException;

/**
 * Includes the colocated `<component>.php` file of a component.
 *
 * This is a deliberate exception from PSR-4. Component PHP lives in the
 * component folder, next to the template and the definition, and no class or
 * namespace is needed.
 *
 * Rules:
 * - The file name is fixed: `<component>.php`. The component name is
 *   validated first.
 * - The real path of the file must lie inside the real path of the component
 *   directory. A symbolic link out of the folder is refused.
 * - The file must print nothing, in any output buffer level it opens. Output is
 *   refused.
 * - It returns a Closure (the alter step) or an array with `alter` and
 *   `replace` Closures. Anything else is refused. The closures have no class
 *   scope and no $this.
 * - A file is included once per request. The result stays in memory.
 *
 * The loader does not read the source of the file. A component file belongs to
 * the theme repository and is as trusted as the rest of that code. The rules
 * for the source (see ComponentFileSourceChecker) are checked in CI with
 * `drush drupal-kit:lint-components`. A file that breaks them is a defect of
 * the
 * theme, not an input to defend against at run time: top-level code in it runs
 * when the file is included, and a second declaration of a function or class is
 * a fatal error that no code can catch.
 *
 * The loader cannot stop a web server from running the file when someone
 * requests its URL. A site should deny `*.php` inside the component folders at
 * the web server. Only the fixed `<component>.php` name is ever loaded, never
 * the `.twig` or `.yaml` beside it.
 *
 * Only a ParseError is recoverable.
 */
class ComponentMapperFileLoader implements ComponentMapperFileLoaderInterface {

  /**
   * The suffix of the colocated file.
   */
  public const SUFFIX = '.php';

  /**
   * Loaded files, keyed by real path.
   *
   * @var array<string, \Drupal\drupal_kit_paragraphs\Mapper\ComponentMapperFile>
   */
  private array $loaded = [];

  /**
   * Constructs a ComponentMapperFileLoader.
   *
   * @param \Drupal\drupal_kit\Component\ComponentDefinitionRepositoryInterface $repository
   *   The repository, to validate component names.
   * @param string $root
   *   The application root. Component directories are relative to it.
   */
  public function __construct(
    private readonly ComponentDefinitionRepositoryInterface $repository,
    private readonly string $root,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function load(ComponentDefinition $definition): ?ComponentMapperFile {
    $name = $definition->getName();
    // The name check comes first. No path is built from a bad name.
    if (!$this->repository->isValidComponentName($name)) {
      throw new InvalidComponentDefinitionException(sprintf('"%s" is not a valid component name.', $name));
    }

    $directory = realpath($this->root . '/' . $definition->getDirectory());
    if ($directory === FALSE) {
      return NULL;
    }
    $candidate = $directory . '/' . $name . self::SUFFIX;
    if (!is_file($candidate)) {
      return NULL;
    }

    $real = realpath($candidate);
    if ($real === FALSE || !str_starts_with($real, $directory . '/')) {
      throw new InvalidComponentDefinitionException(sprintf('The component file %s resolves outside its component directory.', $candidate));
    }

    return $this->loaded[$real] ??= $this->include($real);
  }

  /**
   * Checks the source of a file, includes it and checks what it returned.
   */
  private function include(string $file): ComponentMapperFile {
    $level = ob_get_level();
    ob_start();
    $outputs = [];
    $balanced = FALSE;
    $returned = NULL;
    try {
      // An unscoped static closure: the file sees no $this, no class scope
      // (so `self::` and `static::` are unavailable) and no local variables.
      $include = \Closure::bind(static fn (string $path): mixed => require $path, NULL, NULL);
      $returned = $include($file);
      // The file must leave exactly our buffer open. It may not close ours or
      // open its own. Read every level above ours, not only the innermost: an
      // echo before an ob_start() sits one level down.
      $balanced = ob_get_level() === $level + 1;
      while (ob_get_level() > $level) {
        $outputs[] = (string) ob_get_contents();
        ob_end_clean();
      }
    }
    catch (\ParseError $e) {
      throw new InvalidComponentDefinitionException(sprintf('The component file %s has a syntax error: %s', $file, $e->getMessage()), 0, $e);
    }
    finally {
      // Close every buffer this call opened, also on an exception.
      while (ob_get_level() > $level) {
        ob_end_clean();
      }
    }

    if (implode('', $outputs) !== '') {
      throw new InvalidComponentDefinitionException(sprintf('The component file %s printed output. It must only return.', $file));
    }
    if (!$balanced) {
      throw new InvalidComponentDefinitionException(sprintf('The component file %s changed the output buffer level. It must only return.', $file));
    }

    if ($returned instanceof \Closure) {
      return new ComponentMapperFile($file, $this->unscope($returned, $file));
    }
    if (is_array($returned)) {
      $unknown = array_diff(array_keys($returned), ['alter', 'replace']);
      $alter = $returned['alter'] ?? NULL;
      $replace = $returned['replace'] ?? NULL;
      if ($unknown === [] && ($alter === NULL || $alter instanceof \Closure) && ($replace === NULL || $replace instanceof \Closure) && ($alter !== NULL || $replace !== NULL)) {
        return new ComponentMapperFile(
          $file,
          $alter === NULL ? NULL : $this->unscope($alter, $file),
          $replace === NULL ? NULL : $this->unscope($replace, $file),
        );
      }
    }

    throw new InvalidComponentDefinitionException(sprintf('The component file %s must return a Closure, or an array with an "alter" and/or "replace" Closure.', $file));
  }

  /**
   * Removes the class scope and $this from a closure the file returned.
   */
  private function unscope(\Closure $closure, string $file): \Closure {
    $bound = \Closure::bind($closure, NULL, NULL);
    if ($bound === NULL) {
      throw new InvalidComponentDefinitionException(sprintf('A closure returned by the component file %s cannot be unbound from its class scope.', $file));
    }
    return $bound;
  }

}
