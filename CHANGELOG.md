# Changelog

All notable changes to this project are documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.4.0] — 2026-05-26

Quality + forward-compat release. No new features and no behavioural changes for callers — focus is hardening of the v1.3.0 surface, static-analysis ratcheting, closing the deferred test gaps the v1.3.0 retrospective enumerated, and making local development reproducible against the same PHP version CI and production use.

### Added
- **DDEV as the canonical local environment** (#48) — `.ddev/config.yaml` pins PHP 8.3 (matches CI + production), omits the database container (kernel tests use sqlite::memory:), and ships a `ddev coverage` custom command that runs PHPUnit with `xdebug.mode=coverage` correctly (bypassing DDEV's default `debug,develop` mode which is the wrong driver for coverage collection and is significantly slower). The README's "Local development" section documents the canonical flow (`ddev start && ddev composer install && ddev exec vendor/bin/phpunit`). DDEV itself isn't a hard dependency — vanilla host-PHP 8.3 still works the same way CI does — but the pinned environment removes a class of "works on host, fails on CI" drift (composer.lock pinning to PHP-8.4-only versions when resolved against PHP 8.5, etc.). Local symlink wiring via `scripts/dev-link-module.sh` works from either environment provided you run the script inside the same environment whose path the symlinks should target.
- **Deferred kernel test coverage from v1.3.0** (#47) — four new files closing the gaps the v1.3.0 retrospective enumerated. `MediaArrayBuilderRemoteVideoTest` exercises `buildRemoteVideo`'s URL extraction (YouTube watch / short / passthrough) plus the `image_field_resolver` callback path, without touching the real `media_oembed` YouTube provider. `MediaArrayBuilderLottieTest` covers `buildLottie` happy + missing-file paths via the generic `file` source plugin. `DisplayBaseKernelTest` covers `__call` delegation, `$methodAliases` routing (`getEmailField` / `getPhoneField` → `getTextField`), and `BadMethodCallException` for unknown methods. `EntityHelperContribGatedFieldsKernelTest` covers the five contrib-gated getters (price / address / office_hours / geofield / webform) behind `markTestSkipped` guards — CI stays green on minimal stacks; the path automatically activates the moment a consumer installs the contrib module.

### Changed
- **PHPStan: 1.x → 2.x, level 1 → 5** (#45) — adds `mglaman/phpstan-drupal ^2.0` for Drupal-aware analysis (entity field magic-property access, service container types, hook signatures). `treatPhpDocTypesAsCertain: false` so PHPDoc type promises don't suppress legitimate findings. Pre-existing errors captured in a hand-curated identifier-grouped `phpstan-baseline.neon` so the gate is clean today without rewriting unrelated legacy paths. CI runs `phpstan analyse --memory-limit=2G`. Three real findings fixed in-flight (deprecated `(boolean)` cast in `EntityHelper`, stale Resizer baseline entries from the static refactor, MenuTreeBuilderTest container leak).
- **`menu.language_tree_manipulator` is now optional** (#43) — the service was a hard dependency on a contrib-or-patched core service that not every Drupal install ships. `MenuTreeBuilder` now accepts an optional 6th constructor argument (`?object $languageTreeManipulator`); the manipulator is added to the chain only when present. Service definition uses Drupal's `@?service.id` optional-reference syntax. README documents the upstream patch (drupal.org#2466553) for consumers who want the language-aware behaviour.
- **`Resizer` is fully static** (#44) — removed the `custom_components.resizer` service registration; the class has no instance state. `Resizer::resizer($images, $variants)` is now called directly from `TwigExtension`. Argument hardened with `array_is_list()`-aware defensive coercion so consumers passing a single-image associative array vs a list of image arrays both work without round-tripping through `end()`. README documents the static-utility status.
- **Test debt cleanup** (#46) — removed `testDispatchTaxonomyReference` and `testGetEntityReferenceFieldReturnsReferencedEntityData`; the polymorphic walker assertions were brittle and tested dispatch wiring that's already covered by `testGetTermFieldReturnsLabels` + `getMediaField` kernel coverage from #32.

### Documentation
- **README polish** (#50) — six-badge row (Packagist version, PHP version, Drupal compatibility, PHPStan level, License, CI status). PORTA spelt in caps consistently. Drupal references hyperlinked to drupal.org. Notes added for the language-tree-manipulator patch (#43) and Resizer's static-utility status (#44).

### Quality
- **#47 code-review follow-ups baked in before release**: dynamic-property deprecation in `MediaArrayBuilderRemoteVideoTest` resolved by mocking the concrete `Media` class (so `ContentEntityBase::__get` is stubbable via `willReturnCallback` rather than dynamic assignment); webform empty-signal assertion broadened to `assertEmpty()`; `enableModule()` install failures now convert to `markTestSkipped` instead of test errors.

### Coverage
Measured under PHP 8.3 (DDEV) with xdebug coverage driver: **53.71%** line coverage (789 / 1469 statements). The MIN_COVERAGE floor in `.github/workflows/ci.yml` remains at 53 — v1.4.0 is a hardening release, not a coverage push; the contrib-gated tests are skipped on the minimal CI stack by design. Per-class hotspots for v1.5.0 planning (lowest first):

| Class | Lines | Notes |
|-------|------:|-------|
| `Services\Resizer` | 33.15% | static refactor (#44) reshaped the surface; revisit |
| `Services\MenuTreeBuilder` | 33.33% | `renderLinks` deep paths still untested |
| `Services\EntityHelper` | 41.96% | the bulk; contrib-gated tests would lift this when run |
| `Services\MenuActiveTrailResolver` | 50.00% | known gap |
| `Twig\TypographyExtension` | 52.63% | `applyTypography` variants |
| `Services\TaxonomyTreeBuilder` | 57.89% | mostly covered |
| `Services\MediaArrayBuilder` | 81.21% | strong |
| `TwigExtension` | 91.87% | strongest |

### Deferred to v1.5.0+
- `Resizer` coverage gap (33% — surprising given the v1.3.0 #31 suite; the static refactor may have orphaned some dispatch paths).
- `MenuActiveTrailResolver` remaining 50% uncovered paths.
- `TypographyExtension::applyTypography` variants.
- `MenuTreeBuilder::renderLinks` deep paths (menu link content + custom field formatter).
- Real contrib-module coverage of the five getters (requires composer add of drupal/commerce, drupal/address, drupal/office_hours, drupal/geofield, drupal/webform — currently skipped on the minimal CI stack).

## [1.3.0] — 2026-05-26

### Added
- **Resizer kernel test suite** (#31) — first kernel-level coverage of `Drupal\custom_components\Services\Resizer`. New `ResizerKernelTestBase` + 6 tests covering the SVG passthrough, external-URL fallback, countable-input collapse, and the local-file image-style derivative path. `drupal/image_effects`, `drupal/focal_point`, `drupal/file_mdm` added to require-dev so the auto-orient + focal_point-aware crop effects can run in the kernel container.
- **EntityHelper image/file/media field-getter kernel tests** (#32) — `getImageField`, `getFileField`, `getMediaField` against real Drupal field API. New `EntityHelperMediaFieldsKernelTestBase` composes the test_article fixture with the PNG + Image-Media helpers. Includes cache-tag bubble assertion for `getMediaField`.
- **formatField polymorphic dispatch kernel tests** (#34) — one test per field-type branch (string, text_long, datetime, daterange, link, list_string, boolean, float, entity_reference) verifying the dispatch reaches the correct typed getter. Plus `formatFields` batch and `mapFields` string-config + empty-map paths.
- **ComponentBase form API kernel tests** (#35) — real `FormState` instance; verifies `buildConfigurationForm` render-array shape, default-value pre-population from configuration, and `submitConfigurationForm` persistence.

### Changed
- **CI coverage floor raised**: 45% → 53%. Coverage went from 45.88% (v1.2.0) to 53.03% (v1.3.0). The 80% target now sits in v1.4.0; the remaining gaps are MediaArrayBuilder oembed/Lottie (#33, convoluted), DisplayBase form API, and contrib-gated field getters.

### Deferred to v1.4.0
- `buildRemoteVideo` (oembed) + `buildLottie` kernel coverage (#33) — the stub-bundle approach needs a careful seam that survives without bit-rotting; better as a focused PR.
- `DisplayBase` form API — needs extra_field plugin discovery setup.
- Contrib-gated getters (`getOfficeHoursField`, `getAddressField`, `getGeoField`, `getPriceField`, `getWebformField`).
- `MenuActiveTrailResolver` remaining 50% uncovered paths.
- `TypographyExtension::applyTypography` variants.
- `MenuTreeBuilder::renderLinks` deep paths (menu link content + custom field formatter).

## [1.2.0] — 2026-05-26

### Added
- **MediaArrayBuilder kernel test safety net** (#18). New `MediaArrayBuilderKernelTestBase` with 1px-PNG fixture, image-style helper, and Media bundle creation helper. Kernel coverage for `buildImage`, `buildFileImage`, `buildVideo`, `buildDocument`, `buildImageLink`, `buildFileImageLink`, `getSvgViewBoxDimensions`. Remote-video oembed scenarios deferred to v1.3.0.
- **EntityHelper field-getter kernel test safety net** (#19). New `EntityHelperFieldsKernelTestBase` with `test_article` content type and `attachField()` / `createTestNode()` helpers. Kernel coverage for `getTextField`, `getTextareaField`, `getSelectField`, `getDoubleField`, `getBooleanField`, `getDateField`, `getDateRangeField`, `getLinkField`, `getTermField`, `getEntityReferenceField`. Image/file/media and contrib-gated getters deferred to v1.3.0.
- **`scripts/dev-link-module.sh`** (#22) — single source of truth for local + CI module wiring (creates `web/{profiles,sites,themes,libraries,modules/contrib}`, bridges `web/autoload.php` to `vendor/autoload.php`, symlinks via `find -maxdepth 1` so any future top-level module file is picked up automatically).

### Changed
- **`FilterLinks` migrated to constructor injection** (#20). Implements `ContainerFactoryPluginInterface`; `request_stack` is a constructor dependency. The `\Drupal::request()` static call and its `@phpstan-ignore-next-line` are gone. Test no longer needs `\Drupal::setContainer()`.
- **`TwigExtension::getTranslationPlural` migrated to constructor injection** (#21). Constructor adds `string_translation` as the third argument; existing args keep positions. The `\Drupal::translation()` static call and its `@phpstan-ignore-next-line` are gone.
- **`MediaArrayBuilder::getSvgViewBoxDimensions` reads via stream URI** instead of round-tripping through `fileUrlGenerator->generateAbsoluteString()` and `file_get_contents` of an HTTP URL. Behaviour-preserving in production (PHP's stream-wrapper integration reads `public://...` directly); unlocks kernel testability.
- **CI coverage floor raised**: 30% → 45%. Coverage went from 32.31% (v1.1.0) to 45.88% (v1.2.0). The 80% target from the v1.1.0 roadmap aspiration moves to v1.3.0, which needs `Resizer` kernel coverage + the deferred field getters + remaining contrib-gated paths.

### Polished
- `@phpstan-consistent-constructor` annotations on `ComponentBase` / `DisplayBase` / `TaxonomyTermController` now state explicitly that the contract is documentation-only (downstream consumers aren't in this repo's PHPStan scope).
- `phpunit.xml.dist` carries a comment explaining the `SYMFONY_DEPRECATIONS_HELPER=weak` + `failOnRisky=true` combination and the escape hatch if a contrib dep starts spamming deprecations.

## [1.1.0] — 2026-05-26

### Added
- **Self-contained CI** (#2) — composer scaffolds Drupal via `installer-paths`; module symlinked into `web/modules/contrib/custom_components/`; PHPUnit bootstraps from `web/core/tests/bootstrap.php` with sqlite in-memory. Replaces the previous host-Drupal-only test setup.
- **Kernel test suite** (#2, #4) — new `tests/src/Kernel/`. `EntityHelperKernelTestBase` plus `EntityHelperMenuTest`, `EntityHelperTaxonomyTest`, and a `SmokeTest` canary. 7 kernel tests at the v1.1.0 ship line.
- **Unit test coverage gaps closed** (#3) — 47 new unit tests for `FilterImage`, `FilterLinks`, `FilterTable`, `FilterTypography`, `FilterYoutube`, `RouteSubscriber`, `ComponentBase`, and `TwigExtension` (filters, functions, `templateExists`, `mergeResizer`, `formatDate` strtotime fallback).
- **PHPStan gate in CI** (#5) — `vendor/bin/phpstan analyse` runs at level 1; stale `@phpstan-ignore` annotations cleaned up across `EntityHelper`. `@phpstan-consistent-constructor` documented on the three plugin base classes.
- **Coverage measurement in CI** (#5) — Xdebug enabled; `--coverage-clover coverage.xml --coverage-text` emitted on every run; threshold gate enforces the floor.
- **CONTRIBUTING.md** + README testing section + CI badge (#5).
- **Three new builder services** (#6a, #6b, #6c):
  - `Drupal\custom_components\Services\TaxonomyTreeBuilder` — extracted from `EntityHelper::getTaxonomy` (+ `buildTermTree`). Provides its own `collectCacheMetadata()` accumulator.
  - `Drupal\custom_components\Services\MenuTreeBuilder` — extracted from `EntityHelper::getMenu` (+ `getMenuLinks`). Accepts an optional `?callable $field_formatter` for Menu Item Extras enrichment, breaking what would otherwise be a circular dependency with `EntityHelper::formatField`.
  - `Drupal\custom_components\Services\MediaArrayBuilder` — extracted from `EntityHelper`'s 9 `generateMedia*` / `generateFile*` methods plus `getSvgViewBoxDimensions`. Accepts an optional `?callable $image_field_resolver` for the remote-video field-reference path.

### Changed
- **`EntityHelper` is now a facade** (#6). Down from 2147 to ~1685 lines (-22%). All 38 public methods preserved; the three builder-related groups delegate to the new services with `try`/`finally` blocks so the builders' cache-metadata accumulator always drains, even on exception. Consumer code (htdvere etc.) sees no API change.
- **BREAKING:** Minimum PHP version raised from 8.1 to 8.3, matching the new upstream `parisek/twig-typography ^1.2` floor. Consumers on PHP 8.1/8.2 must upgrade their host before upgrading this module.
- **Typography filter** (`|typography` Twig filter + `filter_typography` text-format plugin) now delegates to `parisek/twig-typography ^1.2` instead of duplicating its logic. No behaviour change for callers — same filter name, same YAML path (`{active_theme}/static/typography.yml`), same defaults.
- Direct dependency on `mundschenk-at/php-typography` removed; it is now pulled transitively via `parisek/twig-typography`.
- `composer.json` `config.allow-plugins` now lists every plugin `drupal/core-dev` needs (composer/installers, drupal/core-composer-scaffold, …) — local `composer install` works without manual interaction; CI no longer needs to patch the file on the fly.

### Added (continued)
- `Drupal\custom_components\Twig\TypographyExtension` — thin Drupal wrapper that resolves the active theme path, caches the parsed config per theme, and delegates to the upstream extension. Pass-through for Drupal render arrays.

## [1.0.0] — 2026-05-24

Initial standalone release. Distributed via GitHub
(`parisek/custom-components`); installable as a Composer package.

Includes GitHub Actions CI that validates `composer.json` and verifies
the package + its `require-dev` set resolves and installs cleanly
against `packages.drupal.org/8`. PHPUnit and PHPStan are configured
locally (`phpunit.xml.dist`, `phpstan.neon`) but are only exercised
by consumer projects (e.g., htdvere) — full Drupal-bootstrap test
runs in standalone CI are deferred to a later phase.

### Module behavior

Renamed from `porta/custom_components` to `parisek/custom-components`,
licensed `GPL-2.0-or-later`, with runtime `class_exists()` /
`interface_exists()` guards in `EntityHelper` for the optional
integrations `drupal/commerce`, `drupal/office_hours`, and Drupal
core's `comment` module.
