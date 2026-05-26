# Custom Components

[![CI](https://github.com/parisek/custom-components/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/parisek/custom-components/actions/workflows/ci.yml)

Base library module for Drupal sites built on the Porta component pattern. Provides shared infrastructure (services, base classes, Twig extensions, image resizer) reused across projects.

## Installation

```bash
composer require parisek/custom-components
drush en custom_components
```

## What this module provides

**Services**

- `custom_components.entity_helper` — high-level entity loading and rendering helpers consumed by display plugins and Twig templates.
- `custom_components.resizer` — image style + focal point + responsive variant generator.
- `custom_components.menu_active_trail_resolver` — resolves the active menu trail accounting for entity references and aliases.
- `custom_components.twig_extension` — registers Twig functions used by component templates.
- `custom_components.typography_twig_extension` — provides the `|typography` Twig filter; delegates to `parisek/twig-typography` and resolves typography config from `{active_theme}/static/typography.yml`.
- `custom_components.route_subscriber` — alters routes for entity access edge cases.

**Base classes**

- `Drupal\custom_components\ComponentBase` — base for component block plugins.
- `Drupal\custom_components\DisplayBase` — base for `extra_field` display plugins that render components.

**Filters**

- `FilterImage`, `FilterLinks`, `FilterTable`, `FilterTypography`, `FilterYoutube` — text format filters.

## Optional integrations

The following Drupal modules are optional. When present, `EntityHelper` automatically exposes additional fields and renderers; when absent, those code paths gracefully no-op.

Contrib (install via Composer):

- `drupal/commerce` — `commerce_product` entity support.
- `drupal/office_hours` — `office_hours` field rendering.

Drupal core (enable via `drush en …`):

- `comment` — comment entity support (`comment_body` field on Comment entities).

## Tests

Tests are self-contained — the package's CI scaffolds Drupal via Composer and runs PHPUnit against `web/core/tests/bootstrap.php` with sqlite in-memory.

To run locally:

```bash
composer install
# Bridge web/autoload.php to vendor/autoload.php (composer scaffold creates web/ itself):
printf '<?php\nreturn require __DIR__ . "/../vendor/autoload.php";\n' > web/autoload.php
# Make Drupal's Extension Discovery see this module:
mkdir -p web/modules/contrib/custom_components
for f in src tests templates *.info.yml *.module *.services.yml composer.json; do
  rm -rf "web/modules/contrib/custom_components/$f"
  ln -s "$PWD/$f" "web/modules/contrib/custom_components/$f"
done

vendor/bin/phpunit
```

Run a specific suite:

```bash
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite kernel
```

Run with coverage (requires Xdebug locally; CI passes `--coverage-clover coverage.xml --coverage-text` automatically):

```bash
vendor/bin/phpunit --coverage-text
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for how to add tests and the unit-vs-kernel decision tree.

## License

GPL-2.0-or-later. See `LICENSE`.
