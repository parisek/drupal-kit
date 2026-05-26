# Custom Components

[![CI](https://github.com/parisek/custom-components/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/parisek/custom-components/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/parisek/custom-components.svg)](https://packagist.org/packages/parisek/custom-components)
[![PHP Version](https://img.shields.io/packagist/php-v/parisek/custom-components.svg)](https://packagist.org/packages/parisek/custom-components)
[![Drupal](https://img.shields.io/badge/Drupal-10%20%7C%2011-0678BE?logo=drupal&logoColor=white)](https://www.drupal.org)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%201-2ecc40)](https://phpstan.org/)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-blue.svg)](https://spdx.org/licenses/GPL-2.0-or-later.html)

Base library module for [Drupal](https://www.drupal.org) sites built on the **PORTA** component pattern. Provides shared infrastructure (services, base classes, [Twig](https://twig.symfony.com/) extensions, image resizer) reused across projects.

## Installation

```bash
composer require parisek/custom-components
drush en custom_components
```

Requires [PHP 8.3+](https://www.php.net/releases/8.3/) and [Drupal](https://www.drupal.org/about/10) 10 or 11.

## What this module provides

**Services**

- `custom_components.entity_helper` — high-level entity loading and rendering helpers consumed by display plugins and Twig templates.
- `custom_components.resizer` — image style + focal point + responsive variant generator.
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

## Tests

Tests are self-contained — the package's CI scaffolds Drupal via Composer and runs [PHPUnit](https://phpunit.de/) against `web/core/tests/bootstrap.php` with sqlite in-memory.

To run locally:

```bash
composer install
scripts/dev-link-module.sh   # symlink module into web/modules/contrib + bridge web/autoload.php
vendor/bin/phpunit
```

The script is what CI runs too, so following it locally produces the same layout.

Run a specific suite:

```bash
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite kernel
```

Run with coverage (requires [Xdebug](https://xdebug.org/) locally; CI passes `--coverage-clover coverage.xml --coverage-text` automatically):

```bash
vendor/bin/phpunit --coverage-text
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for how to add tests and the unit-vs-kernel decision tree.

## Related projects

Part of the **PORTA** ecosystem:

- [`parisek/twig-typography`](https://github.com/parisek/twig-typography) — framework-agnostic typography Twig extension that powers our `|typography` filter.

## License

[GPL-2.0-or-later](https://spdx.org/licenses/GPL-2.0-or-later.html). See [`LICENSE`](LICENSE).
