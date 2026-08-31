<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\Services;

use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Resolves a Vite-built JS entry through the build's `.vite/manifest.json`.
 *
 * WHY THE ENTRY NEEDS A CONTENT HASH. Lazy chunks always carried one; the
 * entry did not, because `*.libraries.yml` names it by a fixed path and a
 * fixed path cannot hold a hash. Cache-busting came from Drupal's `?v=`
 * query instead, and that covers the reference in the HTML — but not the one
 * the bundler emits INSIDE a chunk. When a module is reachable from the entry
 * graph and from a lazy chunk, the bundler hoists it into the entry and the
 * chunk imports it back out as `./script.js`, with no hash and no query. The
 * browser then holds two cache entries for one file: the versioned one
 * updates, the bare one is pinned for as long as `Cache-Control` says.
 *
 * Measured on a downstream site (sloneek, 2026-08-17, WordPress side): 5 of 52
 * chunks imported the entry, `max-age` was 31536000, and a form silently
 * stopped rendering with `The requested module './script.js' does not provide
 * an export named 'n'`. Minified export names are positions in a table, not
 * identities — adding one shared module reassigned `n` from one function to
 * another, so a stale entry can also answer with the WRONG binding and no
 * error at all.
 *
 * A hashed entry closes it at the root: both references name the same
 * immutable file, so they cannot disagree.
 *
 * WHAT THIS DOES NOT FIX. `JsCollectionRenderer` appends a query to every
 * unaggregated asset unconditionally — `version === -1` only switches from
 * `v=<version>` to the global asset query string, and there is no "no query"
 * option. So `script.<hash>.js?v=…` (the HTML tag) and `script.<hash>.js`
 * (a chunk's own import) remain two module identities, fetched and executed
 * twice. They now carry IDENTICAL content, which is the whole point: the
 * correctness defect is gone, a performance cost remains. The WordPress
 * sibling omits the query and closes both; Drupal offers no equivalent short
 * of bypassing the asset pipeline entirely. Documented in ADR 0002.
 *
 * BACKWARDS COMPATIBLE BY CONSTRUCTION. A theme with no manifest at the
 * expected path keeps the path it declared, so this can ship before any
 * consumer changes its build config. Stated precisely, because the code is
 * narrower than "no-op until you rebuild": the trigger is a manifest FILE
 * being present beside the declared asset, not this build having produced it.
 *
 * @see \Drupal\drupal_kit\Services\ViteManifest::isUsableEntryFile()
 */
class ViteManifest {

  /**
   * Default manifest key — the source path Vite was given as its input.
   *
   * THE KEY IS THE INTERFACE, and that is prior art rather than preference.
   * Sage's `JsonManifest::get()` is `$manifest[$asset] ?? $asset`; Laravel's
   * `Vite::chunk()` is `$manifest[$file]` or throw. Neither scans a manifest
   * looking for an `isEntry` record, because a manifest holds one record per
   * input and nothing orders them. A library overrides it per entry with
   * `drupal_kit_vite_entry`.
   */
  public const DEFAULT_ENTRY_KEY = 'src/js/script.js';

  /**
   * The library property that opts a library in and names its manifest key.
   */
  public const LIBRARY_PROPERTY = 'drupal_kit_vite_entry';

  public function __construct(
    private readonly ExtensionPathResolver $extensionPathResolver,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly string $appRoot,
  ) {}

  /**
   * Rewrites opted-in JS assets to the hashed filename from the manifest.
   *
   * @param array<string, array<string, mixed>> $libraries
   *   Library definitions, as handed to hook_library_info_alter().
   * @param string $extension
   *   The module or theme name the libraries belong to.
   */
  public function alterLibraries(array &$libraries, string $extension): void {
    $root = $this->extensionRoot($extension);
    if ($root === NULL) {
      return;
    }

    foreach ($libraries as &$library) {
      $key = $library[self::LIBRARY_PROPERTY] ?? NULL;
      if ($key === TRUE) {
        $key = self::DEFAULT_ENTRY_KEY;
      }
      if (!is_string($key) || $key === '' || empty($library['js'])) {
        continue;
      }

      foreach ($library['js'] as $path => $options) {
        // A path already carrying a scheme or a leading slash is not ours to
        // resolve: it is external or docroot-absolute, and joining it onto the
        // extension root would name a file that does not exist.
        if ($path === '' || $path[0] === '/' || str_contains($path, '://')) {
          continue;
        }

        $dir = $root . '/' . ltrim(dirname($path), './');
        $file = $this->entryFile($dir, $key);
        if ($file === NULL || $file === basename($path)) {
          continue;
        }

        unset($library['js'][$path]);
        $library['js'][dirname($path) . '/' . $file] = $options;
      }
    }
  }

  /**
   * The hashed filename this manifest records for $key, if it is usable.
   *
   * @param string $dir
   *   Absolute path to the built JS directory.
   * @param string $key
   *   Manifest key — the source path Vite was given as its input.
   *
   * @return string|null
   *   Filename inside $dir, never a path; NULL to keep the declared asset.
   */
  public function entryFile(string $dir, string $key = self::DEFAULT_ENTRY_KEY): ?string {
    // is_readable() rather than is_file() alone: an unreadable file passes
    // is_file() and then makes file_get_contents() emit a warning on its way
    // to the same fallback.
    $manifest = $dir . '/.vite/manifest.json';
    if (!is_readable($manifest)) {
      return NULL;
    }

    $decoded = json_decode((string) file_get_contents($manifest), TRUE);
    if (!is_array($decoded)) {
      return NULL;
    }

    $record = $decoded[$key] ?? NULL;
    if (!is_array($record)) {
      return NULL;
    }

    $file = $record['file'] ?? '';

    return is_string($file) && $this->isUsableEntryFile($file, $dir) ? $file : NULL;
  }

  /**
   * Is this manifest `file` value safe to serve as the library's script?
   *
   * Three checks, each answering a reproduction rather than a hypothesis —
   * they are ported from the WordPress sibling, where each was demonstrated:
   *
   * - A BARE FILENAME. `is_file()` happily resolves `../elsewhere/other.js`,
   *   so a `file` carrying a separator escapes the directory this method
   *   documents itself as returning a name inside.
   * - A `.js` SUFFIX. The key decides which RECORD is read, not what its
   *   `file` value says. `{"src/js/script.js":{"file":"style.css"}}` beside an
   *   existing `style.css` resolves to the stylesheet, which would then be
   *   served as a script.
   * - PRESENT ON DISK. A manifest naming a missing file is worse than no
   *   manifest: it serves a guaranteed 404 where the declared path still
   *   worked.
   */
  private function isUsableEntryFile(string $file, string $dir): bool {
    if ($file === '' || basename($file) !== $file) {
      return FALSE;
    }

    if (!str_ends_with(strtolower($file), '.js')) {
      return FALSE;
    }

    return is_file($dir . '/' . $file);
  }

  /**
   * Absolute path to the extension's own directory, or NULL if unknown.
   */
  private function extensionRoot(string $extension): ?string {
    $type = $this->moduleHandler->moduleExists($extension) ? 'module' : 'theme';

    try {
      $path = $this->extensionPathResolver->getPath($type, $extension);
    }
    catch (\Throwable) {
      return NULL;
    }

    return rtrim($this->appRoot, '/') . '/' . ltrim($path, '/');
  }

}
