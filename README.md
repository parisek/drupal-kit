# Custom Components

[![CI](https://github.com/parisek/custom-components/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/parisek/custom-components/actions/workflows/ci.yml)
[![Drupal](https://img.shields.io/badge/Drupal-10%20%7C%2011-0678BE?logo=drupal&logoColor=white)](https://www.drupal.org)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%205-2ecc40)](https://phpstan.org/)
[![Coverage](https://img.shields.io/badge/Coverage-78%25-2ecc40)](.github/workflows/ci.yml)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-blue.svg)](https://spdx.org/licenses/GPL-2.0-or-later.html)

Base library module for [Drupal](https://www.drupal.org) sites built on the **PORTA** component pattern. Provides shared infrastructure (services, base classes, [Twig](https://twig.symfony.com/) extensions, image resizer) reused across projects.

Requires [PHP 8.3+](https://www.php.net/releases/8.3/) and [Drupal](https://www.drupal.org/about/10) 10 or 11.

## What this module provides

**Services**

- `custom_components.entity_helper` — high-level entity loading and rendering helpers consumed by display plugins and Twig templates.
- `Drupal\custom_components\Services\Resizer` — static utility: image style + focal point + responsive variant generator. Call `Resizer::resizer($images, $variants)` directly.
- `custom_components.menu_active_trail_resolver` — resolves the active menu trail accounting for entity references and aliases.
- `custom_components.twig_extension` — registers Twig functions used by component templates.
- `custom_components.typography_twig_extension` — provides the `|typography` Twig filter; delegates to [`parisek/twig-typography`](https://github.com/parisek/twig-typography) and resolves typography config from `{active_theme}/static/typography.yml`.
- `custom_components.route_subscriber` — alters routes for entity access edge cases.

**Base classes**

- `Drupal\custom_components\ComponentBase` — base for component [block plugins](https://www.drupal.org/docs/drupal-apis/block-api/block-api-overview).
- `Drupal\custom_components\DisplayBase` — base for [`extra_field`](https://www.drupal.org/project/extra_field) display plugins that render components.

**Filters**

`FilterImage`, `FilterLinks`, `FilterTable`, `FilterTypography`, `FilterYoutube` — [text format filters](https://www.drupal.org/docs/drupal-apis/filter-api/overview) that normalize editor output into PORTA's component shape.

## Required modules

Pulled automatically by Composer when you install:

- [`drupal/components`](https://www.drupal.org/project/components) — Twig component discovery (`@component/` namespace).
- [`drupal/config_pages`](https://www.drupal.org/project/config_pages) — single-instance config entities for site-wide content.
- [`drupal/extra_field`](https://www.drupal.org/project/extra_field) — extra field display plugins on entities.
- [`drupal/twig_real_content`](https://www.drupal.org/project/twig_real_content) — Twig filter to extract plain text from render arrays.
- [`drupal/twig_tweak`](https://www.drupal.org/project/twig_tweak) — collection of helpful Twig extensions.
- [`parisek/twig-typography`](https://github.com/parisek/twig-typography) — upstream typography filter (powers `|typography`).

## Optional integrations

The following modules are optional. When present, `EntityHelper` automatically exposes additional fields and renderers; when absent, those code paths gracefully no-op.

Contrib (install via Composer):

- [`drupal/commerce`](https://www.drupal.org/project/commerce) — `commerce_product` entity support.
- [`drupal/office_hours`](https://www.drupal.org/project/office_hours) — `office_hours` field rendering.

Drupal core (enable via `drush en …`):

- [`comment`](https://www.drupal.org/docs/8/core/modules/comment) — comment entity support (`comment_body` field on Comment entities).

Drupal core patches (apply via [`cweagans/composer-patches`](https://github.com/cweagans/composer-patches)):

- [drupal.org#2466553](https://www.drupal.org/project/drupal/issues/2466553) — adds `menu.language_tree_manipulator` to Drupal core. When applied, `EntityHelper::getMenu()` filters menu links by the current content language. When absent, the filter step is silently skipped and menu items for all languages appear.

## Local development

Local environment is [DDEV](https://ddev.com/) — pinned to PHP 8.3 in `.ddev/config.yaml` so it matches the production deploy target and CI. The database container is omitted; kernel tests use sqlite in-memory.

```bash
ddev start
ddev composer install
ddev exec scripts/dev-link-module.sh   # symlink module into web/modules/contrib + bridge web/autoload.php
ddev exec vendor/bin/phpunit
```

`scripts/dev-link-module.sh` resolves paths relative to where it runs, so it must be invoked inside the container — otherwise the symlinks point to host paths the container can't see.

### Tests

Tests are self-contained — Composer scaffolds Drupal via [`installer-paths`](https://github.com/composer/installers); PHPUnit bootstraps from `web/core/tests/bootstrap.php`.

```bash
ddev exec vendor/bin/phpunit --testsuite unit
ddev exec vendor/bin/phpunit --testsuite kernel
```

### Coverage

`ddev coverage` is a custom command (defined in `.ddev/commands/web/coverage`) that runs PHPUnit with `xdebug.mode=coverage` and emits both clover XML and a textual summary:

```bash
ddev coverage
ddev coverage --filter ResizerTest   # any phpunit args pass through
```

CI uses the same flags so local + CI numbers stay aligned.

### Step-debugging

```bash
ddev xdebug on    # loads xdebug in debug mode; listen on host port 9003
ddev xdebug off   # debug mode is a heavy perf hit; keep it off by default
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for how to add tests and the unit-vs-kernel decision tree.

### Without DDEV

DDEV is the canonical local environment, but the repo doesn't hard-depend on it — CI runs vanilla `composer install` + `vendor/bin/phpunit` against PHP 8.3 from the [shivammathur/setup-php](https://github.com/shivammathur/setup-php) GitHub Action. If you prefer host-PHP, ensure you're on PHP 8.3 (matching CI / production) to avoid composer.lock drift.

## Releasing

Tag-driven; the package is consumed straight from GitHub via a `vcs` repository entry (no Packagist). Version bumps follow Conventional Commits, the public-API surface and deprecation lifecycle are defined in [RELEASING.md](RELEASING.md) — read it before tagging.

**Distribution scope:** `composer require` ships only the module files, `src/`, `templates/`, `composer.json`, `LICENSE` and `README.md` — everything development-only is `export-ignore`d in `.gitattributes`.

## Related projects

Part of the **PORTA** ecosystem:

- [`parisek/twig-typography`](https://github.com/parisek/twig-typography) — framework-agnostic typography Twig extension that powers our `|typography` filter.

## License

[GPL-2.0-or-later](https://spdx.org/licenses/GPL-2.0-or-later.html). See [`LICENSE`](LICENSE).
