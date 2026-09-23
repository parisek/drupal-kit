# Drupal Kit

[![Packagist Version](https://img.shields.io/packagist/v/parisek/drupal-kit)](https://packagist.org/packages/parisek/drupal-kit)
[![Packagist Downloads](https://img.shields.io/packagist/dt/parisek/drupal-kit)](https://packagist.org/packages/parisek/drupal-kit/stats)
[![CI](https://github.com/parisek/drupal-kit/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/parisek/drupal-kit/actions/workflows/ci.yml)
[![Drupal](https://img.shields.io/badge/Drupal-10%20%7C%2011-0678BE?logo=drupal&logoColor=white)](https://www.drupal.org)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%208-2ecc40)](https://phpstan.org/)
[![Coverage](https://img.shields.io/badge/Coverage-78%25-2ecc40)](.github/workflows/ci.yml)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-blue.svg)](https://spdx.org/licenses/GPL-2.0-or-later.html)

`parisek/drupal-kit` — base library module for [Drupal](https://www.drupal.org) sites built on the **PORTA** component pattern. Provides shared infrastructure (services, base classes, [Twig](https://twig.symfony.com/) extensions, image resizer) reused across projects. The [Drupal](https://www.drupal.org) counterpart of [`parisek/timber-kit`](https://github.com/parisek/timber-kit) (WordPress).

Requires [PHP 8.3+](https://www.php.net/releases/8.3/) and [Drupal](https://www.drupal.org/about/10) 10 or 11.

## Installation

Published on [Packagist](https://packagist.org/packages/parisek/drupal-kit):

```bash
composer require parisek/drupal-kit
drush en drupal_kit
```

## What this module provides

**Services**

- `drupal_kit.entity_helper` — high-level entity loading and rendering helpers consumed by display plugins and Twig templates. Facade over the three builders below.
- `drupal_kit.media_array_builder` — builds the documented array shapes for Media and File entities (image, SVG, video, remote video, document, Lottie).
- `drupal_kit.menu_tree_builder` — renders a menu into the documented item shape (active trail, `field_*` enrichment, subtree scoping via `params['root']`).
- `drupal_kit.taxonomy_tree_builder` — builds nested taxonomy term trees.
- `Drupal\drupal_kit\Services\FeatureFlags` (`drupal_kit.feature_flags`) — reads the opt-in flags in the `drupal_kit.feature_flags` config object, the shape core uses for `system.feature_flags`. `enabled('<flag>')` is FALSE unless the module declares the flag **and** the project set it, which is how hook-level behaviour ships opt-in.
- `Drupal\drupal_kit\Services\Resizer` (`drupal_kit.resizer`) — image style + focal point + responsive variant generator. Take the service, or call the static `Resizer::resizer($images, $variants)` facade, which delegates to it and is kept for backwards compatibility. `$variants` is a list of positional tuples, or an orientation map (`landscape` / `portrait` / `square`) that picks its tuples from the image's own aspect ratio — see [Orientation-aware variants](#orientation-aware-variants).
- `drupal_kit.menu_active_trail_resolver` — resolves the active menu trail accounting for entity references and aliases.
- `drupal_kit.twig_extension` — registers Twig functions used by component templates, including the typography-aware translation helpers `_xt` / `__t` / `_nt` / `_nxt` (translate, then pipe through `|typography`).
- `drupal_kit.typography_twig_extension` — provides the `|typography` Twig filter; delegates to [`parisek/twig-typography`](https://github.com/parisek/twig-typography) and resolves typography config from `{active_theme}/static/typography.yml`.
- `drupal_kit.route_subscriber` — alters routes for entity access edge cases.
- `drupal_kit.config_applier` (`Drupal\drupal_kit\Services\ConfigApplier`) — creates (and, opt-in, updates) an explicit list of config objects through the entity/config API, in dependency order. See [Applying config on deploy](#applying-config-on-deploy).

**Base classes**

- `Drupal\drupal_kit\ComponentBase` — base for component [block plugins](https://www.drupal.org/docs/drupal-apis/block-api/block-api-overview).
- `Drupal\drupal_kit\DisplayBase` — base for [`extra_field`](https://www.drupal.org/project/extra_field) display plugins that render components.

**Filters**

`FilterImage`, `FilterLinks`, `FilterTable`, `FilterTypography`, `FilterYoutube` — [text format filters](https://www.drupal.org/docs/drupal-apis/filter-api/overview) that normalize editor output into PORTA's component shape.

### Orientation-aware variants

`|resizer` takes either positional tuples or one orientation map. The map is
recognised by its keys, so a template that never writes one never changes:

```twig
{# tuples — the historical shape #}
{{ image|resizer(['960', '720', '1280', 'crop'], ['480', '360', '', 'crop']) }}

{# orientation map — the image's own aspect ratio picks the bucket #}
{{ image|resizer({
  landscape: [['960', '720', '1280', 'crop'], ['480', '360', '', 'crop']],
  portrait:  [['720', '960', '1280', 'crop'], ['360', '480', '', 'crop']],
  square:    [['800', '800', '1280', 'crop'], ['400', '400', '', 'crop']],
}) }}
```

An image counts as square while its sides differ by no more than 10 %,
inclusive at both edges; otherwise the longer side decides. A bucket that is
absent or empty falls through to `landscape`, so a map carries only the
orientations that actually differ.

An image with no width or height is classified as `landscape`. That is the
ordinary case rather than an edge case: `MediaArrayBuilder` fills the
dimensions only when the file exists and is a valid image, so a remote file, a
missing file or a broken one arrives with neither.

The same call shape works in [`parisek/timber-kit`](https://github.com/parisek/timber-kit)
and in the styleguide preview, so one template renders on every stack.

## Applying config on deploy

Sites on this stack never run `drush config:import` on deploy — a full import
diffs the *whole* active config store against the repository and would
overwrite production UI edits (menu links, block placement, view filters —
anything an editor changed after launch). `ConfigApplier` and
`drush kit:config-apply` are the supported alternative: they create (and,
opt-in, update) an **explicit, named list** of config objects through the
same entity API core's own config sync uses — `ConfigEntityStorage::createFromStorageRecord()`
/ `updateFromStorageRecord()` — never a raw `\Drupal::configFactory()->save()`.
That matters for config like `field.storage.*`: going through the entity API
is what creates the database column and refreshes the entity field map, not
just the config object.

Guarantees:

- **Never "everything".** You always name the config objects, directly or
  via a list file (`--names-file`, one name per line, `#` comments allowed).
- **Never deletes.** A config present in the active store but absent from
  your list is left untouched, full stop — it is never even considered.
- **Create-only by default.** An existing config is reported `SKIP-EXISTS`
  unless its name is also in `--update`.
- **Update guard.** Pass `--expect-hash name=<sha256>` (from `ConfigApplier::activeHash()`)
  to refuse an update whose *active* value has drifted from the hash you
  captured — protects a production UI edit you didn't know about.
- **Dependency order.** Names are topologically sorted from each config's own
  `dependencies.config` list (`field.storage.*` before `field.field.*`,
  a bundle before its fields, …) — you don't have to list them in order.
  A dependency that is neither in your list nor already active is reported
  `ERROR` and nothing is applied.
- **Idempotent.** Safe to run on every deploy; the second run reports
  `SKIP-EXISTS` for everything it already created.

### CLI

```bash
# Preview only — prints CREATE / SKIP-EXISTS / UPDATE / REFUSE / ERROR, changes nothing.
drush kit:config-apply --names=paragraphs.paragraphs_type.hero,field.storage.paragraph.field_hero_image,field.field.paragraph.hero.field_hero_image --dry-run

# Apply for real.
drush kit:config-apply --names-file=../config-deploy/hero.txt

# Allow one already-existing config to change, guarded by a hash captured earlier.
drush kit:config-apply --names=language.content_settings.paragraph.hero \
  --update=language.content_settings.paragraph.hero \
  --expect-hash=language.content_settings.paragraph.hero=3f9c…
```

`--config-dir` defaults to `config/sync`.

### `hook_post_update_NAME()` pattern

The command above is a manual front end for the same PHP API — call it from
a `hook_post_update_NAME()` in a project module so a new paragraph type (or
any other config) ships through `drush updb` on deploy, the same way schema
changes do:

```php
/**
 * Creates the "hero" paragraph type and its fields.
 */
function my_project_post_update_hero_paragraph_type(): void {
  /** @var \Drupal\drupal_kit\Services\ConfigApplier $applier */
  $applier = \Drupal::service('drupal_kit.config_applier');

  $names = [
    'paragraphs.paragraphs_type.hero',
    'field.storage.paragraph.field_hero_image',
    'field.field.paragraph.hero.field_hero_image',
    'core.entity_view_display.paragraph.hero.default',
    'core.entity_form_display.paragraph.hero.default',
  ];

  $plan = $applier->apply(
    \Drupal::service('extension.list.module')->getPath('my_project') . '/config/deploy',
    $names,
  );

  foreach ($plan as $entry) {
    if ($entry['action'] === 'ERROR') {
      throw new \RuntimeException("kit:config-apply: {$entry['name']}: {$entry['reason']}");
    }
  }
}
```

Re-running `drush updb` on a site that already has the paragraph type is a
no-op — every name comes back `SKIP-EXISTS`. Ship the config as a
`config/deploy`-style directory shipped with the module (not `config/sync`,
which is the site's own export), so the fixture travels with the code that
needs it.

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

- [drupal.org#2466553](https://www.drupal.org/project/drupal/issues/2466553) — adds `menu.language_tree_manipulator` to Drupal core. When applied, `EntityHelper::getMenu()` filters menu links by the current content language. When absent, the filter step is silently skipped and menu items for all languages appear — on multilingual sites the status report (`/admin/reports/status`) shows a warning so the gap is visible.

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

Tag-driven; published on [Packagist](https://packagist.org/packages/parisek/drupal-kit), which syncs tags automatically via the GitHub webhook. Version bumps follow Conventional Commits, the public-API surface and deprecation lifecycle are defined in [RELEASING.md](RELEASING.md) — read it before tagging.

**Distribution scope:** `composer require` ships only the module files, `src/`, `templates/`, `composer.json`, `LICENSE` and `README.md` — everything development-only is `export-ignore`d in `.gitattributes`.

## Related projects

Part of the **PORTA** ecosystem:

- [`parisek/timber-kit`](https://github.com/parisek/timber-kit) — the WordPress counterpart: shared Timber/ACF infrastructure for PORTA themes.
- [`parisek/twig-typography`](https://github.com/parisek/twig-typography) — framework-agnostic typography Twig extension that powers our `|typography` filter.

## License

[GPL-2.0-or-later](https://spdx.org/licenses/GPL-2.0-or-later.html). See [`LICENSE`](LICENSE).
