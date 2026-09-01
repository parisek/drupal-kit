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
   * `vite_entry`.
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
   *   vite_entry: true
   *   js:
   *     dist/js/script.js: { preprocess: false, attributes: { type: module } }
   * @endcode
   *
   * A map states which asset carries which key, which is required once a
   * library declares more than one:
   *
   * @code
   * global:
   *   vite_entry:
   *     dist/js/script.js: src/js/script.js
   *     dist/js/admin.js: src/js/admin.js
   * @endcode
   */
  public const LIBRARY_PROPERTY = 'vite_entry';

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
      if (empty($library['js'])) {
        continue;
      }

      $rewrites = $this->plannedRewrites($library, $root, $extension . '/' . $name);
      if ($rewrites === []) {
        continue;
      }

      // Rebuild rather than unset-and-append. `unset($js[$old])` followed by
      // `$js[$new] = …` moves that asset to the END of the array, and Drupal
      // emits a library's JS in array order: rewriting `vendor.js` while
      // leaving `app.js` alone reordered them, so the bundle that had to run
      // first ran second. Rebuilding in place keeps every position.
      $rebuilt = [];
      foreach ($library['js'] as $path => $options) {
        $rebuilt[$rewrites[(string) $path] ?? $path] = $options;
      }
      $library['js'] = $rebuilt;
    }
  }

  /**
   * The old-path => new-path rewrites to apply to one library, if any.
   *
   * Returns nothing rather than a partial set when the plan is ambiguous, so
   * a library is never left half-rewritten.
   *
   * @param array<string, mixed> $library
   *   The library definition to plan for.
   * @param string $root
   *   Absolute path to the extension's own directory.
   * @param string $label
   *   The `extension/library` label, for the log message.
   *
   * @return array<string, string>
   *   Declared path mapped to the hashed path replacing it.
   */
  private function plannedRewrites(array $library, string $root, string $label): array {
    // Absence of the property is the opt-out, and it is checked HERE only.
    // It used to be checked in the caller as well; with two guards, removing
    // either changed nothing, so no test could demonstrate that opting out
    // works — an invariant nothing can break is also an invariant nothing
    // proves.
    $entry = $library[self::LIBRARY_PROPERTY] ?? NULL;
    if ($entry === NULL) {
      return [];
    }

    $map = \is_array($entry) ? $entry : NULL;
    $key = $entry === TRUE
      ? self::DEFAULT_ENTRY_KEY
      : (\is_string($entry) && $entry !== '' ? $entry : NULL);

    $pairs = [];
    if ($map !== NULL) {
      foreach ($map as $path => $mappedKey) {
        $path = (string) $path;
        if (\is_string($mappedKey) && $mappedKey !== '' && isset($library['js'][$path]) && $this->isResolvablePath($path)) {
          $pairs[$path] = $mappedKey;
        }
      }
    }
    elseif ($key !== NULL) {
      // Which asset does a single key belong to? Matching the key's basename
      // against the declared one looked obvious and is wrong: Vite names its
      // output from the input map's KEY, so `input: {app: 'src/main.js'}`
      // emits `app.js` for the key `src/main.js` and the two never meet.
      //
      // Arity answers it instead. One resolvable asset is unambiguous, so the
      // key belongs to it whatever either is called. More than one is a real
      // choice — applying the key to each collapsed them onto one filename —
      // so the map form is required, and a warning says so.
      $candidates = array_values(array_filter(
        array_map('strval', array_keys($library['js'])),
        fn (string $path): bool => $this->isResolvablePath($path),
      ));

      if (\count($candidates) !== 1) {
        if ($candidates !== []) {
          $this->warn('Library @lib opted in with a single Vite manifest key but declares @count resolvable JS assets. Map each asset to its own key: `@prop: { "path/to/asset.js": "src/js/entry.js" }`.', [
            '@lib' => $label,
            '@count' => \count($candidates),
            '@prop' => self::LIBRARY_PROPERTY,
          ]);
        }
        return [];
      }

      $pairs[$candidates[0]] = $key;
    }

    $rewrites = [];
    foreach ($pairs as $path => $mappedKey) {
      $relDir = \dirname($path);
      $file = $this->entryFile($relDir === '.' ? $root : $root . '/' . $relDir, $mappedKey);
      if ($file === NULL || $file === \basename($path)) {
        continue;
      }
      $rewrites[$path] = $relDir === '.' ? $file : $relDir . '/' . $file;
    }

    // Two assets landing on one output filename would share an array key, and
    // the second insert would discard the first asset's options — the collapse
    // this class already fixed once, arriving through the map. An ambiguous
    // plan is abandoned whole rather than applied in part.
    //
    // The test is the FINAL key set, not the rewrite targets alone. Comparing
    // only `$rewrites` missed a collision against an asset that is not being
    // rewritten: a no-op pair (`$file === basename($path)`) is skipped by the
    // loop above and never enters `$rewrites`, so a rewrite landing on that
    // same untouched filename looked unique and silently overwrote it.
    $final = [];
    foreach (\array_keys($library['js']) as $path) {
      $path = (string) $path;
      $final[] = $rewrites[$path] ?? $path;
    }

    if (\count(\array_unique($final)) !== \count($final)) {
      $this->warn('Library @lib would place two JS assets at the same built filename; no Vite rewrite applied. Check that each `@prop` key names a different Vite input, and that no rewrite lands on an asset the library already declares.', [
        '@lib' => $label,
        '@prop' => self::LIBRARY_PROPERTY,
      ]);
      return [];
    }

    return $rewrites;
  }

  /**
   * Logs a warning on the module's channel.
   *
   * @param string $message
   *   Message with `@placeholder` tokens.
   * @param array<string, mixed> $context
   *   Placeholder values.
   */
  private function warn(string $message, array $context): void {
    $this->logger->get('drupal_kit')->warning($message, $context);
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
   *
   * A backslash is refused outright. Drupal library paths are POSIX, so one
   * appearing here is malformed — and it would slip both guards above, since
   * `..\up\script.js` holds no `/`-delimited `..` segment and `C:\x.js`
   * neither starts with `/` nor carries a scheme.
   */
  private function isResolvablePath(string $path): bool {
    if ($path === '' || $path[0] === '/' || \str_contains($path, '://') || \str_contains($path, '\\')) {
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
    // Every branch below warns before returning NULL. Silence was the first
    // version's real defect: a library that opted in kept its declared path,
    // so a build whose manifest was missing, stale or unreadable served a
    // guaranteed 404 — or, worse, an old fixed filename that still existed,
    // recreating the very cache bug this class fixes. An opt-in that cannot
    // do its job has to say so.
    $manifest = $dir . '/.vite/manifest.json';
    if (!is_readable($manifest)) {
      $this->warn('Vite manifest @path is missing or unreadable; the declared asset path is kept and may 404. Build the theme, or drop the `@prop` opt-in.', [
        '@path' => $manifest,
        '@prop' => self::LIBRARY_PROPERTY,
      ]);
      return NULL;
    }

    $decoded = json_decode((string) file_get_contents($manifest), TRUE);
    if (!is_array($decoded)) {
      $this->warn('Vite manifest @path is not valid JSON; the declared asset path is kept and may 404.', [
        '@path' => $manifest,
      ]);
      return NULL;
    }

    $record = $decoded[$key] ?? NULL;
    if (!is_array($record)) {
      $this->warn('Vite manifest @path has no usable record for key @key. Check that `@prop` names a Vite input, for example `src/js/script.js`.', [
        '@path' => $manifest,
        '@key' => $key,
        '@prop' => self::LIBRARY_PROPERTY,
      ]);
      return NULL;
    }

    $file = $record['file'] ?? '';
    if (!is_string($file) || !$this->isUsableEntryFile($file, $dir)) {
      $this->warn('Vite manifest @path maps @key to @file, which is not a usable script inside the built directory — it escapes the directory, carries a URL-significant character, is not a .js file, or is absent from disk. The declared asset path is kept and may 404.', [
        '@path' => $manifest,
        '@key' => $key,
        '@file' => is_string($file) ? $file : \gettype($file),
      ]);
      return NULL;
    }

    return $file;
  }

  /**
   * Is this manifest `file` value safe to serve as the library's script?
   *
   * Four checks, each answering a reproduction rather than a hypothesis:
   *
   * - RESOLVABLE, NOT BARE. `is_file()` happily resolves
   *   `../elsewhere/other.js`, so a `file` that escapes $dir must be refused.
   *   The check is `isResolvablePath()` — the same per-segment traversal test
   *   the declared library paths get — and NOT `basename($file) !== $file`,
   *   which the first version used. That was too strict: Vite's own default
   *   `entryFileNames` is `assets/[name]-[hash].js`, so a stock config
   *   produces `assets/script-BgkTswcn.js` and every such build was rejected
   *   silently. Only this skeleton's flattened `entryFileNames` ever passed.
   * - URL-SAFE. `#` and `?` are legal in a POSIX filename and would pass every
   *   filesystem check, but a browser reads them as a fragment or query: the
   *   file validated on disk is then not the file requested. Percent signs go
   *   too, since a server may decode `%2e%2e` back into traversal after this
   *   guard has run.
   * - A `.js` SUFFIX. The key decides which RECORD is read, not what its
   *   `file` value says. `{"src/js/script.js":{"file":"style.css"}}` beside an
   *   existing `style.css` resolves to the stylesheet, which would then be
   *   served as a script.
   * - PRESENT ON DISK. A manifest naming a missing file is worse than no
   *   manifest: it serves a guaranteed 404 where the declared path still
   *   worked.
   */
  private function isUsableEntryFile(string $file, string $dir): bool {
    if (!$this->isResolvablePath($file)) {
      return FALSE;
    }

    if (\strpbrk($file, "#?%") !== FALSE) {
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
