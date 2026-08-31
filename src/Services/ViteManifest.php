<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\Services;

use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

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
 * DEPLOY REQUIREMENT. The lookup runs while Drupal builds library info, and
 * that result is cached. A rebuilt bundle is therefore NOT picked up until the
 * library cache is rebuilt — and if the previous hashed file is gone, the
 * cached name 404s until it is. Any deploy that ships new assets must clear
 * caches (`drush cr`), which the deploy scripts on this stack already do.
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
   *
   * Two shapes. `TRUE` or a bare key covers a library with exactly ONE
   * resolvable JS asset — the common case, and unambiguous whatever the asset
   * and the key are called:
   *
   * @code
   * global:
   *   drupal_kit_vite_entry: true
   *   js:
   *     dist/js/script.js: { preprocess: false, attributes: { type: module } }
   * @endcode
   *
   * A map states which asset carries which key, which is required once a
   * library declares more than one:
   *
   * @code
   * global:
   *   drupal_kit_vite_entry:
   *     dist/js/script.js: src/js/script.js
   *     dist/js/admin.js: src/js/admin.js
   * @endcode
   */
  public const LIBRARY_PROPERTY = 'drupal_kit_vite_entry';

  public function __construct(
    private readonly ExtensionPathResolver $extensionPathResolver,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly string $appRoot,
    private readonly LoggerChannelFactoryInterface $logger,
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

    foreach ($libraries as $name => &$library) {
      $entry = $library[self::LIBRARY_PROPERTY] ?? NULL;
      if ($entry === NULL || empty($library['js'])) {
        continue;
      }

      // An explicit map states which declared asset carries which manifest
      // key. `TRUE` or a bare key means "the one JS asset in this library".
      $map = \is_array($entry) ? $entry : NULL;
      $key = $entry === TRUE ? self::DEFAULT_ENTRY_KEY : (\is_string($entry) && $entry !== '' ? $entry : NULL);

      if ($map !== NULL) {
        foreach ($map as $path => $mappedKey) {
          $path = (string) $path;
          if (\is_string($mappedKey) && $mappedKey !== '' && isset($library['js'][$path]) && $this->isResolvablePath($path)) {
            $this->rewriteAsset($library, $root, $path, $mappedKey);
          }
        }
        continue;
      }

      if ($key === NULL) {
        continue;
      }

      // Which asset does a single key belong to? Matching the key's basename
      // against the declared one looked obvious and is wrong: Vite names its
      // output from the input's map KEY, not its filename, so a build with
      // `rollupOptions.input: {app: 'src/main.js'}` emits `app.js` for the
      // manifest key `src/main.js` and the two basenames never meet. That
      // rule silently rewrote nothing for every such build.
      //
      // A library carrying exactly one resolvable JS asset has no ambiguity,
      // so the key belongs to it whatever either is called. More than one and
      // there is a real choice to make — applying the key to each of them is
      // what collapsed several assets onto one filename — so the map form
      // above is required, and a warning says so rather than degrading in
      // silence.
      // array_keys() answers int for a numeric-looking key, so normalise
      // before the string-typed helpers ever see one.
      $candidates = array_values(array_filter(
        array_map('strval', array_keys($library['js'])),
        fn (string $path): bool => $this->isResolvablePath($path),
      ));

      if (\count($candidates) !== 1) {
        if ($candidates !== []) {
          $this->logger->get('drupal_kit')->warning(
            'Library @lib opted into Vite manifest resolution with a single key but declares @count resolvable JS assets. Map each asset to its own key to have them rewritten: `@prop: { "path/to/asset.js": "src/js/entry.js" }`.',
            ['@lib' => $extension . '/' . $name, '@count' => \count($candidates), '@prop' => self::LIBRARY_PROPERTY],
          );
        }
        continue;
      }

      $this->rewriteAsset($library, $root, $candidates[0], $key);
    }
  }

  /**
   * Swaps one declared asset for the hashed filename the manifest records.
   *
   * @param array<string, mixed> $library
   *   The library definition, modified in place.
   * @param string $root
   *   Absolute path to the extension's own directory.
   * @param string $path
   *   The asset path as declared in the library, relative to $root.
   * @param string $key
   *   Manifest key naming this asset's Vite input.
   */
  private function rewriteAsset(array &$library, string $root, string $path, string $key): void {
    // dirname() answers '.' for a bare filename, so branch rather than
    // trimming: `ltrim($dir, './')` is a character mask, not a prefix strip,
    // and it mangles a directory that legitimately starts with a dot
    // (`.build/js` -> `build/js`, pointing at nothing).
    $relDir = \dirname($path);
    $dir = $relDir === '.' ? $root : $root . '/' . $relDir;

    $file = $this->entryFile($dir, $key);
    if ($file === NULL || $file === \basename($path)) {
      return;
    }

    $options = $library['js'][$path];
    unset($library['js'][$path]);
    $library['js'][$relDir === '.' ? $file : $relDir . '/' . $file] = $options;
  }

  /**
   * Is this declared path one this extension can resolve a manifest for?
   *
   * A scheme or a leading slash means external or docroot-absolute; a `..`
   * SEGMENT points outside the extension, where any manifest found belongs to
   * a different build. Joining either onto the extension root would name a
   * file that does not exist.
   *
   * The traversal test is per segment, not `str_contains($path, '..')`: two
   * dots are legal inside a name, and the substring form rejected honest
   * paths like `..build/js/script.js` and `foo..bar/js/script.js`.
   */
  private function isResolvablePath(string $path): bool {
    if ($path === '' || $path[0] === '/' || \str_contains($path, '://')) {
      return FALSE;
    }

    return !\in_array('..', \explode('/', $path), TRUE);
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
