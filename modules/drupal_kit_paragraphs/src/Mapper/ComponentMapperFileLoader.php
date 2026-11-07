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
 * - Before the file is included, its tokens are read. Only the open tag,
 *   whitespace and comments, `declare(strict_types=1);` as the first
 *   statement, `use` statements and then `return` may come first. Named
 *   functions, classes, interfaces, traits, enums and namespaces are refused
 *   anywhere in the file. A file that fails this check never runs.
 * - The file must print nothing, in any output buffer level it opens. Output is
 *   refused.
 * - It returns a Closure (the alter step) or an array with `alter` and
 *   `replace` Closures. Anything else is refused. The closures have no class
 *   scope and no $this.
 * - A file is included once per request. The result stays in memory.
 *
 * The loader cannot stop a web server from running the file when someone
 * requests its URL. Because of the header rule above, such a request runs only
 * the `return` expression, which builds closures and prints nothing, but a site
 * should deny `*.php` inside the component folders at the web server all the
 * same. Only the fixed `<component>.php` name is ever loaded, never the `.twig`
 * or `.yaml` beside it.
 *
 * Only a ParseError is recoverable. A redeclared function or class is a fatal
 * error that no code can catch; the source check above refuses named
 * declarations for that reason. Sites should also run `php -l` on the
 * component `*.php` files in CI.
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
    // Read the tokens first. A file with top-level code never runs.
    $this->assertSafeSource($file);

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

  /**
   * Refuses a file whose source has code the loader does not allow.
   *
   * Only the open tag, whitespace and comments, `declare(strict_types=1);` as
   * the first statement, `use` statements and then `return` may come before the
   * first `return`. Named functions, classes, interfaces, traits, enums and
   * namespaces are refused anywhere: PHP declares them at compile time, and a
   * second declaration is a fatal error that no code can catch.
   *
   * @throws \Drupal\drupal_kit\Component\InvalidComponentDefinitionException
   *   When the file breaks the rules or has a syntax error.
   */
  private function assertSafeSource(string $file): void {
    $source = file_get_contents($file);
    if ($source === FALSE) {
      throw new InvalidComponentDefinitionException(sprintf('The component file %s cannot be read.', $file));
    }
    try {
      $tokens = token_get_all($source, TOKEN_PARSE);
    }
    catch (\ParseError $e) {
      throw new InvalidComponentDefinitionException(sprintf('The component file %s has a syntax error: %s', $file, $e->getMessage()), 0, $e);
    }

    $code = array_values(array_filter(
      $tokens,
      static fn ($token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], TRUE),
    ));
    $this->assertHeader($code, $file);
    $this->assertNoDeclarations($code, $file);
  }

  /**
   * Checks what comes before the first `return`.
   *
   * @param array<int, array{0: int, 1: string, 2: int}|string> $code
   *   The tokens without whitespace and comments.
   * @param string $file
   *   The file, for messages.
   */
  private function assertHeader(array $code, string $file): void {
    $count = count($code);
    if (!isset($code[0]) || !is_array($code[0]) || $code[0][0] !== T_OPEN_TAG) {
      throw $this->unsafe($file, 'it must start with <?php');
    }

    $statements = 0;
    $i = 1;
    while ($i < $count) {
      $token = $code[$i];
      $id = is_array($token) ? $token[0] : NULL;
      if ($id === T_RETURN) {
        return;
      }

      if ($id === T_DECLARE && $statements === 0) {
        $text = '';
        while ($i < $count && $code[$i] !== ';') {
          $text .= is_array($code[$i]) ? $code[$i][1] : $code[$i];
          $i++;
        }
        if (strtolower((string) preg_replace('/\s+/', '', $text)) !== 'declare(strict_types=1)') {
          throw $this->unsafe($file, 'only declare(strict_types=1) is allowed as a declare statement');
        }
        $i++;
        $statements++;
        continue;
      }

      if ($id === T_USE) {
        $i++;
        while ($i < $count && $code[$i] !== ';') {
          $part = $code[$i];
          $part_id = is_array($part) ? $part[0] : NULL;
          $name_tokens = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR, T_AS, T_FUNCTION, T_CONST];
          $allowed = in_array($part_id, $name_tokens, TRUE)
            || in_array($part, [',', '{', '}'], TRUE);
          if (!$allowed) {
            throw $this->unsafe($file, 'a use statement may only import names');
          }
          $i++;
        }
        $i++;
        $statements++;
        continue;
      }

      throw $this->unsafe($file, 'only declare(strict_types=1), use statements and comments may come before return');
    }

    throw $this->unsafe($file, 'it has no return statement');
  }

  /**
   * Refuses declarations that PHP makes at compile time.
   *
   * @param array<int, array{0: int, 1: string, 2: int}|string> $code
   *   The tokens without whitespace and comments.
   * @param string $file
   *   The file, for messages.
   */
  private function assertNoDeclarations(array $code, string $file): void {
    $depth = 0;
    $anonymous_depths = [];
    $pending_anonymous = FALSE;
    $previous = NULL;
    $count = count($code);

    for ($i = 0; $i < $count; $i++) {
      $token = $code[$i];
      $id = is_array($token) ? $token[0] : NULL;

      if (in_array($id, [T_NAMESPACE, T_INTERFACE, T_TRAIT, T_ENUM], TRUE)) {
        throw $this->unsafe($file, 'it declares a namespace, interface, trait or enum');
      }
      if ($id === T_CLASS) {
        if ($previous === T_NEW) {
          $pending_anonymous = TRUE;
        }
        elseif ($previous !== T_DOUBLE_COLON) {
          throw $this->unsafe($file, 'it declares a class');
        }
      }
      if ($id === T_FUNCTION && $anonymous_depths === []) {
        $next = $code[$i + 1] ?? NULL;
        if ($next === '&') {
          $next = $code[$i + 2] ?? NULL;
        }
        if (is_array($next) && $next[0] === T_STRING) {
          throw $this->unsafe($file, 'it declares the named function ' . $next[1]);
        }
      }

      if ($token === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
        $depth++;
        if ($token === '{' && $pending_anonymous) {
          $anonymous_depths[] = $depth;
          $pending_anonymous = FALSE;
        }
      }
      elseif ($token === '}') {
        if ($anonymous_depths !== [] && end($anonymous_depths) === $depth) {
          array_pop($anonymous_depths);
        }
        $depth--;
      }

      $previous = $id;
    }
  }

  /**
   * Builds the exception for a source that breaks the rules.
   */
  private function unsafe(string $file, string $reason): InvalidComponentDefinitionException {
    return new InvalidComponentDefinitionException(sprintf('The component file %s is not allowed: %s. It must only return a closure.', $file, $reason));
  }

}
