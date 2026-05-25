# Custom Components

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

Tests run from a host Drupal site that has this package installed. The package does not ship its own PHPUnit configuration or dev dependencies — use the host project's `phpunit.xml.dist` and `vendor/bin/phpunit`:

```bash
vendor/bin/phpunit --testsuite unit web/modules/custom/custom_components/tests
```

## License

GPL-2.0-or-later. See `LICENSE`.
