<?php

declare(strict_types=1);

namespace Drupal\drupal_kit_paragraphs\Mapper;

/**
 * Checks the source of a colocated component file against the file rules.
 *
 * The loader does not run this check on a request. A component file belongs to
 * the theme repository, so it is as trusted as the rest of that code, and a
 * check on every request costs time. The check runs in CI instead, through
 * `drush drupal-kit:lint-components`, and in the tests.
 *
 * The rules keep a file declarative. Only the open tag, whitespace and
 * comments, `declare(strict_types=1);` as the first statement, `use`
 * statements and then `return` may come before the first `return`. Named
 * functions, classes, interfaces, traits, enums and namespaces are refused
 * anywhere: PHP declares them at compile time, and a second declaration is a
 * fatal error that no code can catch.
 *
 * The class has no Drupal dependency, so a script can load it without a
 * bootstrap.
 */
final class ComponentFileSourceChecker {

  /**
   * Checks a source string.
   *
   * @param string $source
   *   The PHP source of the file.
   *
   * @return string|null
   *   NULL when the source follows the rules, otherwise the reason it does not.
   */
  public static function check(string $source): ?string {
    try {
      $tokens = token_get_all($source, TOKEN_PARSE);
    }
    catch (\ParseError $e) {
      return 'it has a syntax error: ' . $e->getMessage();
    }

    $code = array_values(array_filter(
      $tokens,
      static fn ($token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], TRUE),
    ));
    return self::checkHeader($code) ?? self::checkDeclarations($code);
  }

  /**
   * Checks what comes before the first `return`.
   *
   * @param array<int, array{0: int, 1: string, 2: int}|string> $code
   *   The tokens without whitespace and comments.
   */
  private static function checkHeader(array $code): ?string {
    $count = count($code);
    if (!isset($code[0]) || !is_array($code[0]) || $code[0][0] !== T_OPEN_TAG) {
      return 'it must start with <?php';
    }

    $statements = 0;
    $i = 1;
    while ($i < $count) {
      $token = $code[$i];
      $id = is_array($token) ? $token[0] : NULL;
      if ($id === T_RETURN) {
        return NULL;
      }

      if ($id === T_DECLARE && $statements === 0) {
        $text = '';
        while ($i < $count && $code[$i] !== ';') {
          $text .= is_array($code[$i]) ? $code[$i][1] : $code[$i];
          $i++;
        }
        if (strtolower((string) preg_replace('/\s+/', '', $text)) !== 'declare(strict_types=1)') {
          return 'only declare(strict_types=1) is allowed as a declare statement';
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
            return 'a use statement may only import names';
          }
          $i++;
        }
        $i++;
        $statements++;
        continue;
      }

      return 'only declare(strict_types=1), use statements and comments may come before return';
    }

    return 'it has no return statement';
  }

  /**
   * Refuses declarations that PHP makes at compile time.
   *
   * @param array<int, array{0: int, 1: string, 2: int}|string> $code
   *   The tokens without whitespace and comments.
   */
  private static function checkDeclarations(array $code): ?string {
    $depth = 0;
    $anonymous_depths = [];
    $pending_anonymous = FALSE;
    $previous = NULL;
    $count = count($code);

    for ($i = 0; $i < $count; $i++) {
      $token = $code[$i];
      $id = is_array($token) ? $token[0] : NULL;

      if (in_array($id, [T_NAMESPACE, T_INTERFACE, T_TRAIT, T_ENUM], TRUE)) {
        return 'it declares a namespace, interface, trait or enum';
      }
      if (in_array($id, [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], TRUE)) {
        // An included file can declare a function or class that this check
        // never sees.
        return 'it includes another file';
      }
      if ($id === T_CLASS) {
        if ($previous === T_NEW) {
          $pending_anonymous = TRUE;
        }
        elseif ($previous !== T_DOUBLE_COLON) {
          return 'it declares a class';
        }
      }
      if ($id === T_FUNCTION && $anonymous_depths === []) {
        $next = $code[$i + 1] ?? NULL;
        if ($next === '&') {
          $next = $code[$i + 2] ?? NULL;
        }
        if (is_array($next) && $next[0] === T_STRING) {
          return 'it declares the named function ' . $next[1];
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

    return NULL;
  }

}
