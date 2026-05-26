# Changelog

All notable changes to this project are documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
