<?php

namespace Drupal\Tests\drupal_kit\Unit\Services;

use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Extension\ModuleHandlerInterface;
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
      @rmdir($this->tmpDir);
    }
    if ($this->escapedDir !== NULL && is_dir($this->escapedDir)) {
      array_map('unlink', glob($this->escapedDir . '/*') ?: []);
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
    $libraries = ['global' => ['js' => ['dist/js/script.js' => []]]];
    $before = $libraries;

    $this->vite->alterLibraries($libraries, 'some_theme');

    $this->assertSame($before, $libraries);
  }

}
