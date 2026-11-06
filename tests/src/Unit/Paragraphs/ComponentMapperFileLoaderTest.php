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
    file_put_contents($this->tmp . "/comp/foo/$name.drupal.php", $contents);
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
    file_put_contents($this->tmp . '/outside/foo.drupal.php', "<?php\nreturn static function (): void {};\n");
    symlink($this->tmp . '/outside/foo.drupal.php', $this->tmp . '/comp/foo/foo.drupal.php');

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
    symlink($this->tmp . '/comp/foo/shared.php', $this->tmp . '/comp/foo/foo.drupal.php');

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
   * A file that prints anything is refused, and no buffer is left open.
   *
   * @covers ::load
   */
  public function testOutputIsRefused(): void {
    $this->write("<?php\necho 'leak';\nreturn static function (): void {};\n");
    $level = ob_get_level();

    try {
      $this->loadFoo();
      $this->fail('Expected an exception.');
    }
    catch (InvalidComponentDefinitionException $e) {
      $this->assertStringContainsString('printed output', $e->getMessage());
    }

    $this->assertSame($level, ob_get_level());
  }

  /**
   * Whitespace before the opening tag counts as output.
   *
   * @covers ::load
   */
  public function testLeadingWhitespaceIsOutput(): void {
    $this->write("\n<?php\nreturn static function (): void {};\n");

    $this->expectException(InvalidComponentDefinitionException::class);
    $this->loadFoo();
  }

  /**
   * An exception from the file's own code passes through, buffers closed.
   *
   * @covers ::load
   */
  public function testOwnExceptionPropagates(): void {
    $this->write("<?php\nthrow new \\RuntimeException('own');\n");
    $level = ob_get_level();

    try {
      $this->loadFoo();
      $this->fail('Expected an exception.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('own', $e->getMessage());
    }

    $this->assertSame($level, ob_get_level());
  }

  /**
   * A buffer the file opens and forgets is closed, and its output refused.
   *
   * @covers ::load
   */
  public function testBufferLeftOpenByFileIsClosed(): void {
    $this->write("<?php\nob_start();\necho 'x';\nreturn static function (): void {};\n");
    $level = ob_get_level();

    try {
      $this->loadFoo();
      $this->fail('Expected an exception.');
    }
    catch (InvalidComponentDefinitionException) {
      $this->addToAssertionCount(1);
    }

    $this->assertSame($level, ob_get_level());
  }

  /**
   * The file sees no $this.
   *
   * @covers ::load
   */
  public function testFileHasNoThis(): void {
    $this->write("<?php\nif (isset(\$this)) { throw new \\LogicException('this'); }\nreturn static function (): void {};\n");

    $this->assertNotNull($this->loadFoo());
  }

}
