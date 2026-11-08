<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Unit\Paragraphs;

use Drupal\drupal_kit_paragraphs\Mapper\ComponentFileSourceChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the source rules of colocated component files.
 *
 * @coversDefaultClass \Drupal\drupal_kit_paragraphs\Mapper\ComponentFileSourceChecker
 * @group drupal_kit
 */
class ComponentFileSourceCheckerTest extends TestCase {

  /**
   * A source that breaks the rules is reported with a reason.
   *
   * @covers ::check
   */
  #[DataProvider('rejectedSourceProvider')]
  public function testRejectedSource(string $source): void {
    $reason = ComponentFileSourceChecker::check($source);

    $this->assertNotNull($reason);
    $this->assertNotSame('', $reason);
  }

  /**
   * Data provider for refused sources.
   *
   * @return array<string, array<int, string>>
   *   The cases.
   */
  public static function rejectedSourceProvider(): array {
    $return = 'return static function (): void {};';
    return [
      'function call' => ["<?php\nfile_put_contents('/tmp/x', 'x');\n$return"],
      'echo' => ["<?php\necho 'x';\n$return"],
      'assignment' => ["<?php\n\$a = file_put_contents('/tmp/x', 'x');\n$return"],
      'named function before return' => ["<?php\nfunction evil() { file_put_contents('/tmp/x', 'x'); }\nevil();\n$return"],
      'class before return' => ["<?php\nclass Evil {}\n$return"],
      'require' => ["<?php\nrequire '/tmp/x';\n$return"],
      'inline html' => ["<?php\n?>html<?php\n$return"],
      'namespace' => ["<?php\nnamespace Foo;\n$return"],
      'declare ticks' => ["<?php\ndeclare(ticks=1);\n$return"],
      'declare after use' => ["<?php\nuse Foo\\Bar;\ndeclare(strict_types=1);\n$return"],
      'if statement' => ["<?php\nif (TRUE) { file_put_contents('/tmp/x', 'x'); }\n$return"],
      'named function after return' => ["<?php\n$return\nfunction hoisted() {}\n"],
      'class after return' => ["<?php\n$return\nclass Hoisted {}\n"],
      'interface after return' => ["<?php\n$return\ninterface Hoisted {}\n"],
      'trait after return' => ["<?php\n$return\ntrait Hoisted {}\n"],
      'enum after return' => ["<?php\n$return\nenum Hoisted {}\n"],
      'named function inside a closure' => ["<?php\nreturn static function (): void { function inner() {} };\n"],
      'no return' => ["<?php\n// nothing here\n"],
      'no open tag' => ["return static function (): void {};\n"],
      'close tag before return' => ["<?php ?>\n<?php\n$return"],
    ];
  }

  /**
   * A source that follows the rules gives no reason.
   *
   * @covers ::check
   */
  #[DataProvider('acceptedSourceProvider')]
  public function testAcceptedSource(string $source): void {
    $this->assertNull(ComponentFileSourceChecker::check($source));
  }

  /**
   * Data provider for accepted sources.
   *
   * @return array<string, array<int, string>>
   *   The cases.
   */
  public static function acceptedSourceProvider(): array {
    return [
      'bare return' => ["<?php\nreturn static function (): void {};\n"],
      'comments and doc block' => ["<?php\n\n/**\n * @file\n * Doc.\n */\n\n// A comment.\n# Another.\nreturn static function (): void {};\n"],
      'strict types first' => ["<?php\n\ndeclare(strict_types=1);\n\nreturn static function (): void {};\n"],
      'use statements' => ["<?php\n\nuse Drupal\\drupal_kit\\Services\\EntityHelper;\nuse function Foo\\bar;\nuse const Foo\\BAZ;\nuse Foo\\{Bar, Baz as Qux};\n\nreturn static function (EntityHelper \$fields): void {};\n"],
      'strict types and use' => ["<?php\ndeclare(strict_types=1);\nuse Foo\\Bar;\nreturn static function (): void {};\n"],
      'class constant and anonymous class' => ["<?php\nreturn static function (): object {\n  \$name = \\stdClass::class;\n  return new class(\$name) {\n    public function __construct(public string \$name) {}\n    public function method(): string { return \$this->name; }\n  };\n};\n"],
      'array form' => ["<?php\nreturn ['alter' => static function (): void {}, 'replace' => static fn () => NULL];\n"],
    ];
  }

  /**
   * A syntax error is a reason too.
   *
   * @covers ::check
   */
  public function testSyntaxError(): void {
    $this->assertStringContainsString('syntax error', (string) ComponentFileSourceChecker::check("<?php\nreturn function ( {\n"));
  }

}
