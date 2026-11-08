<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Unit\Paragraphs;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\Core\Theme\ActiveTheme;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\components\Template\ComponentsRegistry;
use Drupal\drupal_kit\Component\ComponentDefinition;
use Drupal\drupal_kit\Component\ComponentDefinitionRepository;
use Drupal\drupal_kit\Component\InvalidComponentDefinitionException;
use Drupal\drupal_kit\Services\EntityHelper;
use Drupal\drupal_kit_paragraphs\Mapper\ComponentMapperFile;
use Drupal\drupal_kit_paragraphs\Mapper\ComponentMapperFileLoader;
use Drupal\drupal_kit_paragraphs\Mapping\MappingContext;
use Drupal\drupal_kit_paragraphs\Mapping\ParagraphMapping;
use Drupal\paragraphs\ParagraphInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/fixtures/stubs/ParagraphInterface.php';

/**
 * Tests for the colocated component file loader.
 *
 * Good files are committed fixtures. Broken ones (syntax error, output,
 * symbolic links) are written to a temporary directory, because a committed
 * syntax error would break the lint and static analysis of the repository.
 *
 * @coversDefaultClass \Drupal\drupal_kit_paragraphs\Mapper\ComponentMapperFileLoader
 * @group drupal_kit
 */
class ComponentMapperFileLoaderTest extends TestCase {

  /**
   * The committed fixtures.
   */
  private string $fixtures;

  /**
   * The temporary root for generated files.
   */
  private string $tmp;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->fixtures = dirname(__DIR__, 3) . '/fixtures/component_files';
    $this->tmp = sys_get_temp_dir() . '/drupal_kit_loader_' . bin2hex(random_bytes(4));
    mkdir($this->tmp . '/comp/foo', 0777, TRUE);
    mkdir($this->tmp . '/outside', 0777, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->remove($this->tmp);
    parent::tearDown();
  }

  /**
   * Removes a directory tree, links included, without following links.
   */
  private function remove(string $path): void {
    if (is_link($path) || is_file($path)) {
      unlink($path);
      return;
    }
    foreach (glob($path . '/*') ?: [] as $child) {
      $this->remove($child);
    }
    if (is_dir($path)) {
      rmdir($path);
    }
  }

  /**
   * Builds a repository, so the loader validates names like production.
   */
  private function repository(?ComponentsRegistry $registry = NULL): ComponentDefinitionRepository {
    $theme = $this->createMock(ActiveTheme::class);
    $theme->method('getName')->willReturn('active');
    $theme_manager = $this->createMock(ThemeManagerInterface::class);
    $theme_manager->method('getActiveTheme')->willReturn($theme);
    return new ComponentDefinitionRepository(
      $registry ?? $this->createMock(ComponentsRegistry::class),
      $theme_manager,
      new MemoryBackend($this->createMock(TimeInterface::class)),
      $this->fixtures,
    );
  }

  /**
   * Builds a loader over the given root.
   */
  private function loader(string $root): ComponentMapperFileLoader {
    return new ComponentMapperFileLoader($this->repository(), $root);
  }

  /**
   * Runs the alter step of a loaded file on a fresh mapping.
   */
  private function runAlter(ComponentMapperFile $file): ParagraphMapping {
    $mapping = new ParagraphMapping('x');
    $alter = $file->getAlter();
    $this->assertNotNull($alter);
    $alter(
      $mapping,
      new MappingContext($this->createMock(ParagraphInterface::class), 'default', new ComponentDefinition('x', 'x/x.yaml', [])),
      $this->createMock(EntityHelper::class),
    );
    return $mapping;
  }

  /**
   * Writes a generated component file.
   */
  private function write(string $contents, string $name = 'foo'): void {
    file_put_contents($this->tmp . "/comp/foo/$name.php", $contents);
  }

  /**
   * Loads the generated foo component.
   */
  private function loadFoo(?ComponentMapperFileLoader $loader = NULL): ?ComponentMapperFile {
    return ($loader ?? $this->loader($this->tmp))->load(new ComponentDefinition('foo', 'comp/foo/foo.yaml', []));
  }

  /**
   * The registry decides the folder, so the active theme wins over the base.
   *
   * The stub plays the registry: it returns the first theme that has the
   * template, active theme first. The loader only follows the definition.
   *
   * @covers ::load
   */
  public function testActiveThemeWinsAndBaseThemeIsTheFallback(): void {
    $registry = $this->createMock(ComponentsRegistry::class);
    $registry->method('getTemplate')->willReturnCallback(function (string $name): ?string {
      $component = explode('/', $name)[1];
      foreach (['theme_active', 'theme_base'] as $theme) {
        if (is_file($this->fixtures . "/$theme/component/$component/$component.yaml")) {
          return "$theme/component/$component/$component.twig";
        }
      }
      return NULL;
    });
    $repository = $this->repository($registry);
    $loader = new ComponentMapperFileLoader($repository, $this->fixtures);

    $winner = $repository->load('precedence');
    $this->assertNotNull($winner);
    $this->assertSame('theme_active/component/precedence', $winner->getDirectory());
    $file = $loader->load($winner);
    $this->assertNotNull($file);
    $this->assertSame('active', $this->runAlter($file)->get('from'));

    $fallback = $repository->load('base-only');
    $this->assertNotNull($fallback);
    $this->assertSame('theme_base/component/base-only', $fallback->getDirectory());
    $fallback_file = $loader->load($fallback);
    $this->assertNotNull($fallback_file);
    $this->assertSame('base-only', $this->runAlter($fallback_file)->get('from'));
  }

  /**
   * A component folder without the file means no PHP step.
   *
   * @covers ::load
   */
  public function testMissingFileIsNull(): void {
    $definition = new ComponentDefinition('no-file', 'theme_active/component/no-file/no-file.yaml', []);
    $this->assertNull($this->loader($this->fixtures)->load($definition));
    $this->assertNull($this->loader($this->fixtures)->load(new ComponentDefinition('gone', 'nowhere/gone/gone.yaml', [])));
  }

  /**
   * A file is included once; the second load returns the same object.
   *
   * @covers ::load
   */
  public function testIncludedOncePerRequest(): void {
    $loader = $this->loader($this->fixtures);
    $definition = new ComponentDefinition('precedence', 'theme_active/component/precedence/precedence.yaml', []);

    $this->assertSame($loader->load($definition), $loader->load($definition));
  }

  /**
   * An array with both steps loads both.
   *
   * @covers ::load
   */
  public function testArrayWithBothSteps(): void {
    $definition = new ComponentDefinition('replacing', 'theme_active/component/replacing/replacing.yaml', []);
    $file = $this->loader($this->fixtures)->load($definition);

    $this->assertNotNull($file);
    $this->assertNotNull($file->getAlter());
    $this->assertNotNull($file->getReplace());
  }

  /**
   * A bad component name is refused before any path is built.
   *
   * @covers ::load
   */
  #[DataProvider('badNameProvider')]
  public function testBadNameIsRefused(string $name): void {
    $this->expectException(InvalidComponentDefinitionException::class);
    $this->loader($this->tmp)->load(new ComponentDefinition($name, 'comp/foo/foo.yaml', []));
  }

  /**
   * Data provider for bad names.
   *
   * @return array<string, array<int, string>>
   *   The cases.
   */
  public static function badNameProvider(): array {
    return [
      'traversal' => ['../outside/evil'],
      'slash' => ['a/b'],
      'upper case' => ['Foo'],
      'empty' => [''],
      'dot' => ['foo.drupal'],
    ];
  }

  /**
   * A symbolic link that leaves the component folder is refused.
   *
   * @covers ::load
   */
  public function testSymlinkOutOfDirectoryIsRefused(): void {
    file_put_contents($this->tmp . '/outside/foo.php', "<?php\nreturn static function (): void {};\n");
    symlink($this->tmp . '/outside/foo.php', $this->tmp . '/comp/foo/foo.php');

    $this->expectException(InvalidComponentDefinitionException::class);
    $this->expectExceptionMessage('outside its component directory');
    $this->loadFoo();
  }

  /**
   * A symbolic link to another file inside the folder is allowed.
   *
   * @covers ::load
   */
  public function testSymlinkInsideDirectoryIsAllowed(): void {
    file_put_contents($this->tmp . '/comp/foo/shared.php', "<?php\nreturn static function (): void {};\n");
    symlink($this->tmp . '/comp/foo/shared.php', $this->tmp . '/comp/foo/foo.php');

    $this->assertNotNull($this->loadFoo());
  }

  /**
   * A syntax error becomes the recoverable exception.
   *
   * @covers ::load
   */
  public function testParseErrorIsRecoverable(): void {
    $this->write("<?php\nreturn function ( {\n");

    $this->expectException(InvalidComponentDefinitionException::class);
    $this->expectExceptionMessage('syntax error');
    $this->loadFoo();
  }

  /**
   * An unsupported return value, or an array without a usable closure, throws.
   *
   * @covers ::load
   */
  #[DataProvider('badReturnProvider')]
  public function testBadReturnIsRecoverable(string $return): void {
    $this->write("<?php\nreturn $return;\n");

    $this->expectException(InvalidComponentDefinitionException::class);
    $this->expectExceptionMessage('must return a Closure');
    $this->loadFoo();
  }

  /**
   * Data provider for bad return values.
   *
   * @return array<string, array<int, string>>
   *   The cases.
   */
  public static function badReturnProvider(): array {
    return [
      'integer' => ['42'],
      'null' => ['NULL'],
      'string' => ["'strlen'"],
      'object' => ['new \\stdClass()'],
      'empty array' => ['[]'],
      'unknown key' => ["['alter' => static function (): void {}, 'extra' => 1]"],
      'alter is no closure' => ["['alter' => 'strlen']"],
      'replace is no closure' => ["['replace' => 1]"],
      'list of closures' => ['[static function (): void {}]'],
    ];
  }

  /**
   * Runs a file whose return expression runs code, and expects a refusal.
   *
   * @param string $body
   *   The body of an immediately invoked closure that the file returns. The
   *   loader's source check allows this: the code sits in the return
   *   expression.
   * @param string $message
   *   Part of the expected exception message.
   */
  private function assertRefusedAndBuffersRestored(string $body, string $message): void {
    $this->write("<?php\nreturn (static function (): \\Closure {\n$body\n  return static function (): void {};\n})();\n");
    $level = ob_get_level();

    try {
      $this->loadFoo();
      $this->fail('Expected an exception.');
    }
    catch (InvalidComponentDefinitionException $e) {
      $this->assertStringContainsString($message, $e->getMessage());
    }

    $this->assertSame($level, ob_get_level());
  }

  /**
   * Output printed before the file opens a buffer of its own is refused.
   *
   * The file's own ob_start() hides our buffer from ob_get_contents(), so only
   * a check of every level finds the echo.
   *
   * @covers ::load
   */
  public function testEchoBeforeObStartIsRefused(): void {
    $this->assertRefusedAndBuffersRestored("echo 'LEAK'; ob_start();", 'printed output');
  }

  /**
   * Output printed in a buffer that the file opens is refused.
   *
   * @covers ::load
   */
  public function testEchoAfterObStartIsRefused(): void {
    $this->assertRefusedAndBuffersRestored("ob_start(); echo 'LEAK';", 'printed output');
  }

  /**
   * A buffer with a callback is refused, and the callback hides nothing.
   *
   * @covers ::load
   */
  public function testObStartWithCallbackIsRefused(): void {
    $this->assertRefusedAndBuffersRestored("ob_start(static fn (string \$buffer): string => ''); echo 'LEAK';", 'printed output');
  }

  /**
   * Leaks at several nested levels are refused.
   *
   * @covers ::load
   */
  public function testNestedLeaksAreRefused(): void {
    $this->assertRefusedAndBuffersRestored("echo 'a'; ob_start(); echo 'b'; ob_start(); echo 'c';", 'printed output');
  }

  /**
   * A buffer that is opened and left empty is refused: the level changed.
   *
   * @covers ::load
   */
  public function testEmptyBufferLeftOpenIsRefused(): void {
    $this->assertRefusedAndBuffersRestored('ob_start();', 'output buffer level');
  }

  /**
   * A file that closes the loader's own buffer is refused.
   *
   * @covers ::load
   */
  public function testClosingTheLoaderBufferIsRefused(): void {
    $this->assertRefusedAndBuffersRestored('ob_end_clean();', 'output buffer level');
  }

  /**
   * Whitespace before the opening tag refuses the file.
   *
   * @covers ::load
   */
  public function testLeadingWhitespaceIsRefused(): void {
    $this->write("\n<?php\nreturn static function (): void {};\n");

    $this->expectException(InvalidComponentDefinitionException::class);
    $this->loadFoo();
  }

  /**
   * Headers that the loader accepts.
   *
   * @covers ::load
   */
  #[DataProvider('acceptedSourceProvider')]
  public function testAcceptedSourcesLoad(string $source): void {
    $this->write($source);

    $this->assertNotNull($this->loadFoo());
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
   * The closures have no class scope: `self::` and `static::` fail.
   *
   * @covers ::load
   */
  #[DataProvider('scopeProvider')]
  public function testClosuresHaveNoClassScope(string $expression): void {
    $this->write("<?php\nreturn static function (): string { return $expression; };\n");
    $file = $this->loadFoo();
    $this->assertNotNull($file);
    $alter = $file->getAlter();
    $this->assertNotNull($alter);

    $this->expectException(\Error::class);
    $alter(new ParagraphMapping(), new MappingContext($this->createMock(ParagraphInterface::class), 'default', new ComponentDefinition('foo', 'x/x.yaml', [])), $this->createMock(EntityHelper::class));
  }

  /**
   * Data provider for scope expressions.
   *
   * @return array<string, array<int, string>>
   *   The cases.
   */
  public static function scopeProvider(): array {
    return [
      'self' => ['self::class'],
      'static' => ['static::class'],
    ];
  }

  /**
   * The closures of the array form have no class scope either.
   *
   * @covers ::load
   */
  public function testArrayFormClosuresHaveNoClassScope(): void {
    $this->write("<?php\nreturn ['replace' => static function (): string { return self::class; }];\n");
    $file = $this->loadFoo();
    $replace = $file?->getReplace();
    $this->assertNotNull($replace);

    $this->expectException(\Error::class);
    $replace();
  }

  /**
   * A returned closure that uses $this cannot be unbound, so it is refused.
   *
   * PHP warns when it cannot unbind $this. The test swallows that warning.
   *
   * @covers ::load
   */
  public function testReturnedClosureUsingThisIsRefused(): void {
    $this->write("<?php\nreturn (new class { public function make(): \\Closure { return function (): bool { return isset(\$this); }; } })->make();\n");

    set_error_handler(static fn (): bool => TRUE);
    try {
      $this->loadFoo();
      $this->fail('Expected an exception.');
    }
    catch (InvalidComponentDefinitionException $e) {
      $this->assertStringContainsString('cannot be unbound', $e->getMessage());
    }
    finally {
      restore_error_handler();
    }
  }

}
