<?php

namespace Drupal\Tests\drupal_kit\Unit\Services;

use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\drupal_kit\Services\ViteManifest;
use PHPUnit\Framework\TestCase;

/**
 * Tests the Vite manifest entry resolver.
 *
 * @coversDefaultClass \Drupal\drupal_kit\Services\ViteManifest
 * @group drupal_kit
 */
class ViteManifestTest extends TestCase {

  /**
   * The system under test.
   */
  protected ViteManifest $vite;

  /**
   * Temp directory standing in for a built JS directory.
   */
  protected string $tmpDir;

  /**
   * Sibling directory used by the traversal test, removed in tearDown().
   */
  protected ?string $escapedDir = NULL;

  /**
   * Dot-prefixed build directory used by one test, removed in tearDown().
   */
  protected ?string $dotDir = NULL;

  /**
   * Logger for viteRootedAtTmpDir(), when a test asserts on it.
   */
  protected ?LoggerChannelFactoryInterface $logger = NULL;

  /**
   * Extra fixture directories to remove in tearDown().
   *
   * @var string[]
   */
  protected array $extraDirs = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->tmpDir = sys_get_temp_dir() . '/drupal_kit_vite_' . uniqid();
    mkdir($this->tmpDir . '/.vite', 0777, TRUE);

    $this->vite = new ViteManifest(
      $this->createMock(ExtensionPathResolver::class),
      $this->createMock(ModuleHandlerInterface::class),
      '',
      $this->nullLogger(),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (is_dir($this->tmpDir)) {
      array_map('unlink', glob($this->tmpDir . '/.vite/*') ?: []);
      array_map('unlink', glob($this->tmpDir . '/*.js') ?: []);
      array_map('unlink', glob($this->tmpDir . '/*.css') ?: []);
      @rmdir($this->tmpDir . '/.vite');
      // Deepest first: unlink() cannot remove a directory, so a nested
      // fixture dir must be gone before its parent is swept.
      foreach (['/dist/js/.vite', '/dist/js/assets', '/dist/js', '/dist'] as $sub) {
        array_map('unlink', glob($this->tmpDir . $sub . '/*') ?: []);
        @rmdir($this->tmpDir . $sub);
      }
      @rmdir($this->tmpDir);
    }
    foreach (array_reverse($this->extraDirs) as $dir) {
      array_map('unlink', glob($dir . '/.vite/*') ?: []);
      @rmdir($dir . '/.vite');
      array_map('unlink', glob($dir . '/*') ?: []);
      @rmdir($dir);
    }
    if ($this->dotDir !== NULL && is_dir($this->dotDir)) {
      array_map('unlink', glob($this->dotDir . '/.vite/*') ?: []);
      @rmdir($this->dotDir . '/.vite');
      array_map('unlink', glob($this->dotDir . '/*') ?: []);
      @rmdir($this->dotDir);
      @rmdir(dirname($this->dotDir));
    }
    if ($this->escapedDir !== NULL && is_dir($this->escapedDir)) {
      $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($this->escapedDir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
      );
      foreach ($it as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
      }
      @rmdir($this->escapedDir);
    }
    parent::tearDown();
  }

  /**
   * Writes a manifest mapping the default key to $file.
   */
  protected function writeManifest(string $file): void {
    file_put_contents(
      $this->tmpDir . '/.vite/manifest.json',
      json_encode([ViteManifest::DEFAULT_ENTRY_KEY => ['file' => $file]]),
    );
  }

  /**
   * @covers ::entryFile
   *
   * The happy path this class exists for: the manifest names a hashed file
   * that is present, so the hashed name is returned.
   */
  public function testResolvesHashedEntryFromManifest(): void {
    touch($this->tmpDir . '/script.BgkTswcn.min.js');
    $this->writeManifest('script.BgkTswcn.min.js');

    $this->assertSame('script.BgkTswcn.min.js', $this->vite->entryFile($this->tmpDir));
  }

  /**
   * @covers ::entryFile
   *
   * Backwards compatible by construction — a build that never emitted a
   * manifest keeps whatever the library declared.
   */
  public function testMissingManifestKeepsDeclaredAsset(): void {
    $this->assertNull($this->vite->entryFile($this->tmpDir));
  }

  /**
   * @covers ::entryFile
   *
   * A malformed manifest is not a reason to take the site's JS down.
   */
  public function testMalformedManifestKeepsDeclaredAsset(): void {
    file_put_contents($this->tmpDir . '/.vite/manifest.json', '{not json');

    $this->assertNull($this->vite->entryFile($this->tmpDir));
  }

  /**
   * @covers ::entryFile
   *
   * The key is the interface: a manifest holding other records but not this
   * key resolves nothing, rather than guessing at an `isEntry` record.
   */
  public function testUnknownKeyKeepsDeclaredAsset(): void {
    file_put_contents(
      $this->tmpDir . '/.vite/manifest.json',
      json_encode(['src/js/other.js' => ['file' => 'other.abcdefgh.js']]),
    );

    $this->assertNull($this->vite->entryFile($this->tmpDir));
  }

  /**
   * @covers ::isUsableEntryFile
   *
   * A `file` carrying a separator escapes the directory this method promises
   * to return a name inside — `is_file()` alone would resolve it happily.
   */
  public function testTraversingFileIsRejected(): void {
    // The traversed file must EXIST, or `is_file()` rejects it first and this
    // asserts nothing about the separator guard. Verified by mutation: with
    // the guard removed and the target absent, this test still passed.
    $sibling = $this->tmpDir . '/../' . basename($this->tmpDir) . '-escaped';
    mkdir($sibling, 0777, TRUE);
    touch($sibling . '/other.js');
    $this->escapedDir = $sibling;

    $this->writeManifest('../' . basename($sibling) . '/other.js');

    $this->assertNull($this->vite->entryFile($this->tmpDir));
  }

  /**
   * @covers ::isUsableEntryFile
   *
   * The key decides which RECORD is read, not what its `file` says. A
   * stylesheet under the JS key must not be served as a script.
   */
  public function testNonJsFileIsRejected(): void {
    touch($this->tmpDir . '/style.css');
    $this->writeManifest('style.css');

    $this->assertNull($this->vite->entryFile($this->tmpDir));
  }

  /**
   * @covers ::isUsableEntryFile
   *
   * A manifest naming a missing file is worse than no manifest: it would
   * serve a guaranteed 404 where the declared path still worked.
   */
  public function testMissingFileOnDiskIsRejected(): void {
    $this->writeManifest('script.NeverBuilt.min.js');

    $this->assertNull($this->vite->entryFile($this->tmpDir));
  }

  /**
   * @covers ::alterLibraries
   *
   * A library without the opt-in property is untouched, even when a usable
   * manifest sits beside its asset.
   */
  public function testLibraryWithoutOptInIsUntouched(): void {
    // Everything a rewrite needs is in place EXCEPT the opt-in, so the
    // assertion can only be explained by the opt-in guard. With the shared
    // unrooted mock this passed even with that guard deleted, because
    // extensionRoot() returned NULL long before it was reached.
    $this->buildDistJs('script.BgkTswcn.min.js');
    $libraries = ['global' => ['js' => ['dist/js/script.js' => []]]];
    $before = $libraries;
    // Same library WITH the property is rewritten (asserted elsewhere), so
    // the only difference here is the opt-in.
    $this->viteRootedAtTmpDir()->alterLibraries($libraries, 'some_theme');

    $this->assertSame($before, $libraries);
  }

  /**
   * A resolver whose extension root is $this->tmpDir, with `dist/js` inside.
   *
   * Mirrors the real shape: the library declares `dist/js/script.js` relative
   * to the extension, so the manifest is looked for at
   * `<extension>/dist/js/.vite/manifest.json`.
   */
  protected function viteRootedAtTmpDir(): ViteManifest {
    $resolver = $this->createMock(ExtensionPathResolver::class);
    $resolver->method('getPath')->willReturn($this->tmpDir);
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->willReturn(FALSE);

    return new ViteManifest($resolver, $moduleHandler, '', $this->logger ?? $this->nullLogger());
  }

  /**
   * A logger factory whose channel swallows everything.
   */
  protected function nullLogger(): LoggerChannelFactoryInterface {
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));
    return $factory;
  }

  /**
   * Puts a usable manifest at $subDir under tmpDir.
   *
   * A path resolving there WOULD then be rewritten, which is what makes a
   * "left alone" assertion mean something instead of passing for lack of
   * anything to find.
   */
  protected function buildManifestAt(string $subDir, string $file = 'script.BgkTswcn.min.js'): void {
    $dir = rtrim($this->tmpDir . '/' . ltrim($subDir, '/'), '/');
    if (!is_dir($dir . '/.vite')) {
      mkdir($dir . '/.vite', 0777, TRUE);
    }
    touch($dir . '/' . $file);
    file_put_contents(
      $dir . '/.vite/manifest.json',
      json_encode([ViteManifest::DEFAULT_ENTRY_KEY => ['file' => $file]]),
    );
    $this->extraDirs[] = $dir;
  }

  /**
   * Creates `dist/js` under tmpDir holding $file and a manifest naming it.
   */
  protected function buildDistJs(string $file, ?string $key = NULL): string {
    $dir = $this->tmpDir . '/dist/js';
    mkdir($dir . '/.vite', 0777, TRUE);
    touch($dir . '/' . $file);
    file_put_contents(
      $dir . '/.vite/manifest.json',
      json_encode([($key ?? ViteManifest::DEFAULT_ENTRY_KEY) => ['file' => $file]]),
    );
    return $dir;
  }

  /**
   * @covers ::alterLibraries
   *
   * The path this class exists for: an opted-in library's declared asset is
   * replaced by the hashed filename, in place, keeping its options.
   */
  public function testAlterLibrariesRewritesToHashedFile(): void {
    $this->buildDistJs('script.BgkTswcn.min.js');
    $libraries = [
      'global' => [
        'vite_entry' => ViteManifest::DEFAULT_ENTRY_KEY,
        'js' => ['dist/js/script.js' => ['preprocess' => FALSE]],
      ],
    ];

    $this->viteRootedAtTmpDir()->alterLibraries($libraries, 'some_theme');

    $this->assertSame(
      ['dist/js/script.BgkTswcn.min.js' => ['preprocess' => FALSE]],
      $libraries['global']['js'],
    );
  }

  /**
   * @covers ::alterLibraries
   *
   * `true` is shorthand for the default key, so the common case needs no
   * literal in every consumer's libraries.yml.
   */
  public function testTrueOptsInWithTheDefaultKey(): void {
    $this->buildDistJs('script.DeE43lKH.min.js');
    $libraries = [
      'global' => [
        'vite_entry' => TRUE,
        'js' => ['dist/js/script.js' => []],
      ],
    ];

    $this->viteRootedAtTmpDir()->alterLibraries($libraries, 'some_theme');

    $this->assertArrayHasKey('dist/js/script.DeE43lKH.min.js', $libraries['global']['js']);
  }

  /**
   * @covers ::alterLibraries
   *
   * A docroot-absolute or external path is not ours to resolve: joining it
   * onto the extension root would name a file that does not exist.
   */
  public function testAbsoluteAndExternalPathsAreLeftAlone(): void {
    $this->buildDistJs('script.BgkTswcn.min.js');
    // One at a time: with both declared, arity (two candidates) would explain
    // the untouched library just as well as the path rules, and the test
    // passed with both rules deleted.
    // Without the guards these resolve to `<root>//themes/custom/x/dist/js`
    // and `<root>/https:/cdn.example.com`; a manifest is planted at both so a
    // missing guard rewrites instead of quietly finding nothing.
    $this->buildManifestAt('/themes/custom/x/dist/js');
    $this->buildManifestAt('/https:/cdn.example.com');

    foreach (['/themes/custom/x/dist/js/script.js', 'https://cdn.example.com/script.js'] as $path) {
      $libraries = ['global' => ['vite_entry' => TRUE, 'js' => [$path => []]]];
      $before = $libraries;

      $this->viteRootedAtTmpDir()->alterLibraries($libraries, 'some_theme');

      $this->assertSame($before, $libraries, $path . ' is left alone');
    }
  }

  /**
   * @covers ::alterLibraries
   *
   * Opted in, but the build never emitted a manifest — the declared asset is
   * served unchanged. This is the shape every consumer has before it changes
   * its build config.
   */
  public function testOptedInWithoutManifestKeepsDeclaredAsset(): void {
    mkdir($this->tmpDir . '/dist/js', 0777, TRUE);
    $libraries = [
      'global' => ['vite_entry' => TRUE, 'js' => ['dist/js/script.js' => []]],
    ];
    $before = $libraries;

    $this->viteRootedAtTmpDir()->alterLibraries($libraries, 'some_theme');

    $this->assertSame($before, $libraries);
  }

  /**
   * @covers ::alterLibraries
   *
   * An extension whose path cannot be resolved (uninstalled, renamed) must
   * leave every library alone rather than let the exception reach the asset
   * pipeline — a broken lookup should cost the hashed filename, not the page.
   */
  public function testUnresolvableExtensionLeavesLibrariesAlone(): void {
    $resolver = $this->createMock(ExtensionPathResolver::class);
    $resolver->method('getPath')->willThrowException(new \RuntimeException('unknown extension'));
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->willReturn(TRUE);
    $vite = new ViteManifest($resolver, $moduleHandler, '', $this->nullLogger());

    $libraries = [
      'global' => ['vite_entry' => TRUE, 'js' => ['dist/js/script.js' => []]],
    ];
    $before = $libraries;

    $vite->alterLibraries($libraries, 'gone_module');

    $this->assertSame($before, $libraries);
  }

  /**
   * @covers ::alterLibraries
   *
   * REGRESSION. The loop applied the one manifest key to every JS file in the
   * library, so each resolved the same hashed name and the rewrites overwrote
   * each other — a library declaring `vendor.js` beside `script.js` came out
   * with a single asset. A bare key now covers only a library with ONE
   * resolvable asset; more than one is a real choice the map form has to make,
   * and the warning says so instead of degrading in silence.
   */
  public function testSeveralAssetsUnderOneKeyAreRefused(): void {
    $this->buildDistJs('script.BgkTswcn.min.js');
    touch($this->tmpDir . '/dist/js/vendor.js');
    $libraries = [
      'global' => [
        'vite_entry' => TRUE,
        'js' => [
          'dist/js/vendor.js' => ['weight' => -1],
          'dist/js/script.js' => [],
        ],
      ],
    ];

    $channel = $this->createMock(LoggerChannelInterface::class);
    $channel->expects($this->once())->method('warning');
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($channel);
    $this->logger = $factory;
    $before = $libraries;

    $this->viteRootedAtTmpDir()->alterLibraries($libraries, 'some_theme');

    $this->assertSame($before, $libraries, 'nothing is rewritten and nothing is lost');
  }

  /**
   * @covers ::alterLibraries
   *
   * A `..` segment points outside the extension, where any manifest found
   * belongs to a different build. Previously `ltrim($dir, './')` flattened
   * `../x/script.js` to a lookup under `<extension>/x/` while still emitting
   * the escaping path.
   */
  public function testParentRelativePathsAreLeftAlone(): void {
    // The escaped directory must hold a USABLE manifest, otherwise "nothing
    // resolved there" explains the untouched library and the test says
    // nothing about the traversal rule — it passed with the rule deleted.
    $sibling = $this->tmpDir . '-sibling/dist/js';
    mkdir($sibling . '/.vite', 0777, TRUE);
    touch($sibling . '/script.BgkTswcn.min.js');
    file_put_contents(
      $sibling . '/.vite/manifest.json',
      json_encode([ViteManifest::DEFAULT_ENTRY_KEY => ['file' => 'script.BgkTswcn.min.js']]),
    );
    $this->escapedDir = $this->tmpDir . '-sibling';

    $relative = '../' . basename($this->tmpDir) . '-sibling/dist/js/script.js';
    $libraries = ['global' => ['vite_entry' => TRUE, 'js' => [$relative => []]]];
    $before = $libraries;

    $this->viteRootedAtTmpDir()->alterLibraries($libraries, 'some_theme');

    $this->assertSame($before, $libraries);
  }

  /**
   * @covers ::alterLibraries
   *
   * A dot-prefixed directory must not be mangled. `ltrim($dir, './')` is a
   * character mask, so `.build/js` became `build/js` — a lookup against a
   * directory that does not exist, silently keeping the unhashed asset.
   */
  public function testDotPrefixedDirectoryResolves(): void {
    $dir = $this->tmpDir . '/.build/js';
    mkdir($dir . '/.vite', 0777, TRUE);
    touch($dir . '/script.BgkTswcn.min.js');
    file_put_contents(
      $dir . '/.vite/manifest.json',
      json_encode([ViteManifest::DEFAULT_ENTRY_KEY => ['file' => 'script.BgkTswcn.min.js']]),
    );
    $this->dotDir = $dir;
    $libraries = [
      'global' => ['vite_entry' => TRUE, 'js' => ['.build/js/script.js' => []]],
    ];

    $this->viteRootedAtTmpDir()->alterLibraries($libraries, 'some_theme');

    $this->assertArrayHasKey('.build/js/script.BgkTswcn.min.js', $libraries['global']['js']);
  }

  /**
   * @covers ::alterLibraries
   *
   * A bare filename has dirname() '.', which must not leak a `./` prefix into
   * the rewritten library path.
   */
  public function testBareFilenameRewritesWithoutDotPrefix(): void {
    // setUp() already created tmpDir/.vite — the manifest for a bare filename
    // lives there, so only the file and the record are needed.
    touch($this->tmpDir . '/script.BgkTswcn.min.js');
    file_put_contents(
      $this->tmpDir . '/.vite/manifest.json',
      json_encode([ViteManifest::DEFAULT_ENTRY_KEY => ['file' => 'script.BgkTswcn.min.js']]),
    );
    $libraries = [
      'global' => ['vite_entry' => TRUE, 'js' => ['script.js' => []]],
    ];

    $this->viteRootedAtTmpDir()->alterLibraries($libraries, 'some_theme');

    $this->assertSame(['script.BgkTswcn.min.js' => []], $libraries['global']['js']);
  }

  /**
   * @covers ::alterLibraries
   *
   * REGRESSION. Vite names its output from the input map's KEY, not the source
   * filename, so `rollupOptions.input: {app: 'src/main.js'}` emits `app.js`
   * under the manifest key `src/main.js`. The basename-matching rule this
   * replaces compared `app.js` with `main.js`, never matched, and silently
   * rewrote nothing for every build shaped that way.
   */
  public function testEntryWhoseOutputNameDiffersFromItsSourceIsRewritten(): void {
    $dir = $this->tmpDir . '/dist/js';
    mkdir($dir . '/.vite', 0777, TRUE);
    touch($dir . '/app.BgkTswcn.min.js');
    file_put_contents(
      $dir . '/.vite/manifest.json',
      json_encode(['src/main.js' => ['file' => 'app.BgkTswcn.min.js']]),
    );
    $libraries = [
      'global' => [
        'vite_entry' => 'src/main.js',
        'js' => ['dist/js/app.js' => []],
      ],
    ];

    $this->viteRootedAtTmpDir()->alterLibraries($libraries, 'some_theme');

    $this->assertSame(['dist/js/app.BgkTswcn.min.js' => []], $libraries['global']['js']);
  }

  /**
   * @covers ::alterLibraries
   *
   * The map form states which asset carries which key, so a library holding
   * several entries keeps them all — the case a single key has to refuse.
   */
  public function testMapFormRewritesEachAssetUnderItsOwnKey(): void {
    $dir = $this->tmpDir . '/dist/js';
    mkdir($dir . '/.vite', 0777, TRUE);
    touch($dir . '/script.AAAAAAAA.min.js');
    touch($dir . '/admin.BBBBBBBB.min.js');
    file_put_contents(
      $dir . '/.vite/manifest.json',
      json_encode([
        'src/js/script.js' => ['file' => 'script.AAAAAAAA.min.js'],
        'src/js/admin.js' => ['file' => 'admin.BBBBBBBB.min.js'],
      ]),
    );
    $libraries = [
      'global' => [
        'vite_entry' => [
          'dist/js/script.js' => 'src/js/script.js',
          'dist/js/admin.js' => 'src/js/admin.js',
        ],
        'js' => ['dist/js/script.js' => [], 'dist/js/admin.js' => ['weight' => 1]],
      ],
    ];

    $this->viteRootedAtTmpDir()->alterLibraries($libraries, 'some_theme');

    $this->assertSame(
      ['dist/js/script.AAAAAAAA.min.js' => [], 'dist/js/admin.BBBBBBBB.min.js' => ['weight' => 1]],
      $libraries['global']['js'],
    );
  }

  /**
   * @covers ::isResolvablePath
   *
   * REGRESSION. Two dots are legal inside a directory name. Testing the whole
   * path with `str_contains($path, '..')` rejected `..build/js/script.js`,
   * which traverses nothing — the traversal test has to look at segments.
   */
  public function testDirectoryNameContainingTwoDotsIsNotMistakenForTraversal(): void {
    $dir = $this->tmpDir . '/..build/js';
    mkdir($dir . '/.vite', 0777, TRUE);
    touch($dir . '/script.BgkTswcn.min.js');
    file_put_contents(
      $dir . '/.vite/manifest.json',
      json_encode([ViteManifest::DEFAULT_ENTRY_KEY => ['file' => 'script.BgkTswcn.min.js']]),
    );
    $this->dotDir = $dir;
    $libraries = [
      'global' => ['vite_entry' => TRUE, 'js' => ['..build/js/script.js' => []]],
    ];

    $this->viteRootedAtTmpDir()->alterLibraries($libraries, 'some_theme');

    $this->assertArrayHasKey('..build/js/script.BgkTswcn.min.js', $libraries['global']['js']);
  }

  /**
   * @covers ::alterLibraries
   *
   * REGRESSION. `unset()` + append moved a rewritten asset to the end of the
   * array, and Drupal emits a library's JS in array order. Rewriting only
   * `vendor.js` therefore made it run AFTER `app.js`, inverting a dependency
   * the library declared by position.
   */
  public function testRewritingOneAssetKeepsTheDeclaredOrder(): void {
    $dir = $this->tmpDir . '/dist/js';
    mkdir($dir . '/.vite', 0777, TRUE);
    touch($dir . '/vendor.AAAAAAAA.min.js');
    file_put_contents(
      $dir . '/.vite/manifest.json',
      json_encode(['src/js/vendor.js' => ['file' => 'vendor.AAAAAAAA.min.js']]),
    );
    $libraries = [
      'global' => [
        'vite_entry' => ['dist/js/vendor.js' => 'src/js/vendor.js'],
        'js' => ['dist/js/vendor.js' => [], 'dist/js/app.js' => []],
      ],
    ];

    $this->viteRootedAtTmpDir()->alterLibraries($libraries, 'some_theme');

    $this->assertSame(
      ['dist/js/vendor.AAAAAAAA.min.js', 'dist/js/app.js'],
      array_keys($libraries['global']['js']),
      'the rewritten asset keeps its position',
    );
  }

  /**
   * @covers ::alterLibraries
   *
   * REGRESSION. Two map entries resolving to one built filename land on the
   * same array key, and the second insert discards the first asset's options
   * — the round-1 collapse, arriving through the map. An ambiguous plan is
   * abandoned whole rather than applied in part.
   */
  public function testMapEntriesCollidingOnOneFilenameRewriteNothing(): void {
    $dir = $this->tmpDir . '/dist/js';
    mkdir($dir . '/.vite', 0777, TRUE);
    touch($dir . '/bundle.AAAAAAAA.min.js');
    file_put_contents(
      $dir . '/.vite/manifest.json',
      json_encode([
        'src/js/a.js' => ['file' => 'bundle.AAAAAAAA.min.js'],
        'src/js/b.js' => ['file' => 'bundle.AAAAAAAA.min.js'],
      ]),
    );
    $libraries = [
      'global' => [
        'vite_entry' => [
          'dist/js/a.js' => 'src/js/a.js',
          'dist/js/b.js' => 'src/js/b.js',
        ],
        'js' => ['dist/js/a.js' => ['weight' => -1], 'dist/js/b.js' => []],
      ],
    ];
    $channel = $this->createMock(LoggerChannelInterface::class);
    $channel->expects($this->once())->method('warning');
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($channel);
    $this->logger = $factory;
    $before = $libraries;

    $this->viteRootedAtTmpDir()->alterLibraries($libraries, 'some_theme');

    $this->assertSame($before, $libraries, 'neither asset is dropped');
  }

  /**
   * @covers ::plannedRewrites
   *
   * The warning has to name the library and the property, or it sends a
   * reader nowhere. Asserting only that `warning()` fired let a garbled
   * message through.
   */
  public function testTheMultiAssetWarningNamesTheLibraryAndProperty(): void {
    $this->buildDistJs('script.BgkTswcn.min.js');
    touch($this->tmpDir . '/dist/js/vendor.js');
    $channel = $this->createMock(LoggerChannelInterface::class);
    $channel->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('@prop'),
        $this->callback(static fn (array $c): bool => ($c['@lib'] ?? NULL) === 'some_theme/global'
          && ($c['@count'] ?? NULL) === 2
          && ($c['@prop'] ?? NULL) === ViteManifest::LIBRARY_PROPERTY),
      );
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($channel);
    $this->logger = $factory;
    $libraries = [
      'global' => [
        'vite_entry' => TRUE,
        'js' => ['dist/js/vendor.js' => [], 'dist/js/script.js' => []],
      ],
    ];

    $this->viteRootedAtTmpDir()->alterLibraries($libraries, 'some_theme');
  }

  /**
   * @covers ::isResolvablePath
   *
   * Drupal library paths are POSIX, so a backslash is malformed — and it
   * would slip both other guards, carrying no `/`-delimited `..` segment and
   * neither a leading slash nor a scheme.
   */
  public function testBackslashPathsAreRefused(): void {
    // dirname() answers '.' for a backslash path on POSIX, so the manifest
    // has to sit at the extension root for a missing guard to bite.
    $this->buildManifestAt('');
    $libraries = [
      'global' => ['vite_entry' => TRUE, 'js' => ['..\\up\\script.js' => []]],
    ];
    $before = $libraries;

    $this->viteRootedAtTmpDir()->alterLibraries($libraries, 'some_theme');

    $this->assertSame($before, $libraries);
  }

  /**
   * @covers ::plannedRewrites
   *
   * A property that is neither TRUE, a non-empty string, nor a map says
   * nothing usable. It must be ignored rather than coerced into a key.
   */
  public function testMalformedPropertyValueIsIgnored(): void {
    $this->buildDistJs('script.BgkTswcn.min.js');

    foreach ([123, FALSE, ''] as $value) {
      $libraries = [
        'global' => ['vite_entry' => $value, 'js' => ['dist/js/script.js' => []]],
      ];
      $before = $libraries;

      $this->viteRootedAtTmpDir()->alterLibraries($libraries, 'some_theme');

      $this->assertSame($before, $libraries, var_export($value, TRUE) . ' is ignored');
    }
  }

  /**
   * @covers ::plannedRewrites
   *
   * Map entries that name nothing usable are skipped individually — a typo in
   * one row must not cost the rows around it.
   */
  public function testUnusableMapRowsAreSkippedWithoutLosingTheRest(): void {
    $this->buildDistJs('script.BgkTswcn.min.js');
    $libraries = [
      'global' => [
        'vite_entry' => [
          'dist/js/script.js' => 'src/js/script.js',
          'dist/js/absent.js' => 'src/js/absent.js',
          'dist/js/script.js.map' => 123,
        ],
        'js' => ['dist/js/script.js' => []],
      ],
    ];

    $this->viteRootedAtTmpDir()->alterLibraries($libraries, 'some_theme');

    $this->assertSame(['dist/js/script.BgkTswcn.min.js' => []], $libraries['global']['js']);
  }

  /**
   * @covers ::plannedRewrites
   *
   * A manifest naming exactly what the library already declares is a no-op,
   * not a rewrite to the same key — the case of an unhashed build.
   */
  public function testManifestNamingTheDeclaredFileChangesNothing(): void {
    $dir = $this->tmpDir . '/dist/js';
    mkdir($dir . '/.vite', 0777, TRUE);
    touch($dir . '/script.js');
    file_put_contents(
      $dir . '/.vite/manifest.json',
      json_encode([ViteManifest::DEFAULT_ENTRY_KEY => ['file' => 'script.js']]),
    );
    $libraries = [
      'global' => ['vite_entry' => TRUE, 'js' => ['dist/js/script.js' => ['weight' => 2]]],
    ];
    $before = $libraries;

    $this->viteRootedAtTmpDir()->alterLibraries($libraries, 'some_theme');

    $this->assertSame($before, $libraries);
  }

  /**
   * @covers ::alterLibraries
   *
   * A library declaring no JS at all is skipped before anything is read.
   */
  public function testLibraryWithoutJsIsSkipped(): void {
    $libraries = [
      'global' => ['vite_entry' => TRUE, 'css' => ['theme' => ['dist/css/style.css' => []]]],
    ];
    $before = $libraries;

    $this->viteRootedAtTmpDir()->alterLibraries($libraries, 'some_theme');

    $this->assertSame($before, $libraries);
  }

  /**
   * @covers ::isUsableEntryFile
   *
   * REGRESSION. Vite's own default `entryFileNames` is
   * `assets/[name]-[hash].js`, so a stock config emits a `file` carrying a
   * subdirectory. The first guard was `basename($file) !== $file`, which
   * rejected every such build — silently, since nothing logged. Only this
   * skeleton's flattened `entryFileNames` reached the happy path, so the
   * feature looked healthy here while doing nothing downstream.
   */
  public function testVitesDefaultAssetsSubdirectoryIsAccepted(): void {
    mkdir($this->tmpDir . '/assets', 0777, TRUE);
    touch($this->tmpDir . '/assets/script-BgkTswcn.js');
    $this->extraDirs[] = $this->tmpDir . '/assets';
    $this->writeManifest('assets/script-BgkTswcn.js');

    $this->assertSame(
      'assets/script-BgkTswcn.js',
      $this->vite->entryFile($this->tmpDir),
    );
  }

  /**
   * @covers ::isUsableEntryFile
   *
   * A subdirectory is allowed; escaping the built directory still is not.
   * The relaxation must not have reopened traversal.
   */
  public function testSubdirectoryRelaxationStillRefusesTraversal(): void {
    $this->writeManifest('../script.BgkTswcn.js');
    touch(dirname($this->tmpDir) . '/script.BgkTswcn.js');

    $this->assertNull($this->vite->entryFile($this->tmpDir));

    @unlink(dirname($this->tmpDir) . '/script.BgkTswcn.js');
  }

  /**
   * @covers ::isUsableEntryFile
   *
   * `#` and `?` are legal in a POSIX filename and pass every filesystem
   * check, but a browser reads them as a fragment or query — so the file
   * validated on disk is not the file the page requests. `%` goes too: a
   * server may decode `%2e%2e` back into traversal after this guard ran.
   */
  public function testUrlSignificantCharactersAreRefused(): void {
    foreach (['script.js#.js', 'script.js?.js', 'script%2e.js'] as $name) {
      touch($this->tmpDir . '/' . $name);
      $this->writeManifest($name);

      $this->assertNull(
        $this->vite->entryFile($this->tmpDir),
        $name . ' must not be served',
      );

      @unlink($this->tmpDir . '/' . $name);
    }
  }

  /**
   * @covers ::entryFile
   *
   * REGRESSION. Every failure branch returned NULL in silence, so an opted-in
   * library kept its declared path and could serve a 404 with nothing in the
   * log to explain it. Each distinct failure now warns exactly once.
   *
   * @dataProvider provideUnusableManifests
   */
  public function testEveryManifestFailureWarns(callable $arrange): void {
    $channel = $this->createMock(LoggerChannelInterface::class);
    $channel->expects($this->once())->method('warning');
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($channel);

    $arrange($this);

    $vite = new ViteManifest(
      $this->createMock(ExtensionPathResolver::class),
      $this->createMock(ModuleHandlerInterface::class),
      '',
      $factory,
    );

    $this->assertNull($vite->entryFile($this->tmpDir));
  }

  /**
   * Manifest states that must each produce exactly one warning.
   */
  public static function provideUnusableManifests(): array {
    return [
      'missing manifest' => [
        static function (self $t): void {},
      ],
      'malformed JSON' => [
        static function (self $t): void {
          file_put_contents($t->tmpDir . '/.vite/manifest.json', '{not json');
        },
      ],
      'key absent' => [
        static function (self $t): void {
          file_put_contents(
            $t->tmpDir . '/.vite/manifest.json',
            json_encode(['src/js/other.js' => ['file' => 'other.abcdefgh.js']]),
          );
        },
      ],
      'file absent from disk' => [
        static function (self $t): void {
          $t->writeManifest('script.BgkTswcn.min.js');
        },
      ],
      'file is not a script' => [
        static function (self $t): void {
          touch($t->tmpDir . '/style.css');
          $t->writeManifest('style.css');
        },
      ],
    ];
  }

  /**
   * @covers ::plannedRewrites
   *
   * REGRESSION. The collision test compared rewrite targets only. A pair whose
   * manifest already names the declared file is a no-op, skipped before
   * `$rewrites` is built — so a rewrite landing on that same untouched
   * filename looked unique, and then overwrote the untouched asset's options
   * during the rebuild. The test is now the final key set.
   */
  public function testRewriteCollidingWithAnUntouchedAssetIsRefused(): void {
    touch($this->tmpDir . '/bundle.ABC.js');
    file_put_contents(
      $this->tmpDir . '/.vite/manifest.json',
      json_encode([
        'src/js/a.js' => ['file' => 'bundle.ABC.js'],
        'src/js/b.js' => ['file' => 'bundle.ABC.js'],
      ]),
    );

    $libraries = [
      'main' => [
        ViteManifest::LIBRARY_PROPERTY => [
          'a.js' => 'src/js/a.js',
          'bundle.ABC.js' => 'src/js/b.js',
        ],
        'js' => [
          'a.js' => ['weight' => -1],
          'bundle.ABC.js' => ['weight' => 1],
        ],
      ],
    ];

    $channel = $this->createMock(LoggerChannelInterface::class);
    $channel->expects($this->once())->method('warning');
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($channel);
    $this->logger = $factory;
    $before = $libraries;

    $this->viteRootedAtTmpDir()->alterLibraries($libraries, 'some_theme');

    $this->assertSame(
      $before,
      $libraries,
      'an ambiguous plan is abandoned whole, so no options set is lost',
    );
  }

  /**
   * @covers ::isUsableEntryFile
   *
   * REGRESSION. Allowing a subdirectory reopened a hole the bare-filename
   * rule had closed by accident: `is_file()` follows symlinks, so a `assets`
   * link pointing outside the build satisfies every string check and still
   * resolves elsewhere. Containment is now asserted with realpath().
   */
  public function testSymlinkedSubdirectoryEscapingTheBuildIsRefused(): void {
    $outside = sys_get_temp_dir() . '/drupal_kit_vite_outside_' . uniqid();
    mkdir($outside, 0777, TRUE);
    touch($outside . '/payload.js');
    $this->escapedDir = $outside;

    symlink($outside, $this->tmpDir . '/assets');
    $this->writeManifest('assets/payload.js');

    $this->assertNull($this->vite->entryFile($this->tmpDir));

    @unlink($this->tmpDir . '/assets');
  }

  /**
   * @covers ::alterLibraries
   *
   * The subdirectory acceptance proven end to end rather than on
   * entryFile() alone: the rewritten library keeps the `assets/` segment, so
   * the emitted asset path is the one the build actually produced.
   */
  public function testSubdirectoryEntrySurvivesTheLibraryRewrite(): void {
    mkdir($this->tmpDir . '/dist/js/assets', 0777, TRUE);
    touch($this->tmpDir . '/dist/js/assets/script-BgkTswcn.js');
    $this->extraDirs[] = $this->tmpDir . '/dist/js/assets';
    mkdir($this->tmpDir . '/dist/js/.vite', 0777, TRUE);
    file_put_contents(
      $this->tmpDir . '/dist/js/.vite/manifest.json',
      json_encode([
        ViteManifest::DEFAULT_ENTRY_KEY => ['file' => 'assets/script-BgkTswcn.js'],
      ]),
    );

    $libraries = [
      'main' => [
        ViteManifest::LIBRARY_PROPERTY => TRUE,
        'js' => ['dist/js/script.js' => ['weight' => -1]],
      ],
    ];

    $this->viteRootedAtTmpDir()->alterLibraries($libraries, 'some_theme');

    $this->assertSame(
      ['dist/js/assets/script-BgkTswcn.js' => ['weight' => -1]],
      $libraries['main']['js'],
    );
  }

}
