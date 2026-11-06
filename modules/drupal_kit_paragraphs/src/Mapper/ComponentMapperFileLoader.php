<?php

declare(strict_types=1);

namespace Drupal\drupal_kit_paragraphs\Mapper;

use Drupal\drupal_kit\Component\ComponentDefinition;
use Drupal\drupal_kit\Component\ComponentDefinitionRepositoryInterface;
use Drupal\drupal_kit\Component\InvalidComponentDefinitionException;

/**
 * Includes the colocated `<component>.drupal.php` file of a component.
 *
 * This is a deliberate exception from PSR-4. Component PHP lives in the
 * component folder, next to the template and the definition, and no class or
 * namespace is needed.
 *
 * Rules:
 * - The file name is fixed: `<component>.drupal.php`. The component name is
 *   validated first.
 * - The real path of the file must lie inside the real path of the component
 *   directory. A symbolic link out of the folder is refused.
 * - The file must start with `return` and print nothing. Output is refused.
 * - It returns a Closure (the alter step) or an array with `alter` and
 *   `replace` Closures. Anything else is refused.
 * - A file is included once per request. The result stays in memory.
 *
 * A web server may run a `.php` file under a theme when someone requests its
 * URL. The file prints nothing, so a direct request shows an empty page, but
 * a site should deny `*.drupal.php` at the web server all the same.
 */
class ComponentMapperFileLoader implements ComponentMapperFileLoaderInterface {

  /**
   * The suffix of the colocated file.
   */
  public const SUFFIX = '.drupal.php';

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
   * Includes a file and checks what it returned.
   */
  private function include(string $file): ComponentMapperFile {
    $level = ob_get_level();
    ob_start();
    try {
      // A static closure: the file sees no $this and no local variables.
      $returned = (static fn (string $path): mixed => require $path)($file);
      $output = (string) ob_get_contents();
    }
    catch (\ParseError $e) {
      throw new InvalidComponentDefinitionException(sprintf('The component file %s has a syntax error: %s', $file, $e->getMessage()), 0, $e);
    }
    finally {
      // Close exactly the buffers this call opened, also on an exception.
      while (ob_get_level() > $level) {
        ob_end_clean();
      }
    }

    if ($output !== '') {
      throw new InvalidComponentDefinitionException(sprintf('The component file %s printed output. It must only return.', $file));
    }

    if ($returned instanceof \Closure) {
      return new ComponentMapperFile($file, $returned);
    }
    if (is_array($returned)) {
      $unknown = array_diff(array_keys($returned), ['alter', 'replace']);
      $alter = $returned['alter'] ?? NULL;
      $replace = $returned['replace'] ?? NULL;
      if ($unknown === [] && ($alter === NULL || $alter instanceof \Closure) && ($replace === NULL || $replace instanceof \Closure) && ($alter !== NULL || $replace !== NULL)) {
        return new ComponentMapperFile($file, $alter, $replace);
      }
    }

    throw new InvalidComponentDefinitionException(sprintf('The component file %s must return a Closure, or an array with an "alter" and/or "replace" Closure.', $file));
  }

}
