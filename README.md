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
- `drupal_kit.menu_locations` (`Drupal\drupal_kit\Services\MenuLocations`) — resolves a theme-declared menu "slot" (`menu_locations:` in `<theme>.info.yml`) to the menu assigned to it, and returns its items in `EntityHelper::getMenu()`'s shape. One menu per slot in the default language, assigned on the admin form at `/admin/structure/menu/locations`; a genuinely different menu per language is optional, through Drupal's native `config_translation` module. See [Menu locations](#menu-locations).

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

## Menu locations

A theme declares named "slots" for the menus it renders — header, hamburger,
footer columns. A site builder assigns ONE real menu to each slot on one
admin form. The theme reads the slot's items as data. This is the pattern
WordPress calls `register_nav_menus()`; `MenuLocations` is the Drupal
equivalent.

It replaces a pattern several projects grew independently: a "menu_block"
block plugin placed in a theme region, configured with a per-language menu
select, used only to hand that menu's items to a component template. The
block placement carried no visual meaning — the region was never printed —
so the block was a menu picker wearing a block. `MenuLocations` is that
picker, without the block.

**One menu per slot in the default language.** A menu link
(`menu_link_content`) is itself content-translatable, and
`EntityHelper::getMenu()` already returns the current content language's
translation — that is how every other menu-driven part of this module has
always worked, and it is enough for most sites: one menu, its links
translated. Point the slot at that one menu; translate its links with
content translation, the same as any other translatable content, and leave
the per-language assignment below alone entirely.

A site that genuinely needs a DIFFERENT menu per language — not just
different translated links in the same menu — gets that through Drupal's
own, optional **config_translation** module (see
[Per-language menus](#per-language-menus-optional) below), never through a
second key bolted onto this config. A config-level "menu per language"
layer sitting next to content translation is a second translation
mechanism for the same fact, and the two drift: a real example from a
downstream theme carried `main-en` with a footer address translated as
"ARKERO, DE", while `main`'s own German translation read "ARKERO GmbH,
DE" — two independent copies of the same fact, disagreeing.

### Declaring slots

Add a `menu_locations` key to the theme's `<theme>.info.yml`, machine name to
label, the same shape as `regions:`:

```yaml
menu_locations:
  header_menu: 'Header'
  hamburger_menu: 'Header (mobile)'
  footer_menu1: 'Footer (primary)'
  footer_menu2: 'Footer (secondary)'
  footer_menu3: 'Footer (tertiary)'
```

`MenuLocations::slots()` reads this from the currently **active** theme —
theme negotiation's answer for the request, read through `theme.manager`,
not `system.theme:default`. That matters on a site running more than one
front-end theme (domain-negotiated multi-brand, for instance): each active
theme reads its own declared slots and its own assignment, not always the
site's configured default. An unset key, or a theme with no such key,
returns an empty array — a theme with no `menu_locations` behaves exactly
as one without any menu slots.

### Assigning menus

`/admin/structure/menu/locations` — a local task next to core's own **Menus**
page — lists ONE `<select>` per slot, offering every menu on the site plus
"- None -". No per-language fieldsets: a slot takes one menu, full stop.
Requires the `administer menu` permission, the same one menu_ui itself
requires: assigning a menu to a slot needs no more authority than placing a
menu block does.

Unlike the runtime lookup above, **the form always edits the site's default
(frontend) theme** (`system.theme:default`), never the theme rendering the
admin route it lives on — an admin theme's own `menu_locations` (if it
declared any) has no form of its own. This is deliberate: an editor opening
this page expects to configure the theme visitors normally see, not
whichever theme happens to be active on the admin page they are looking at.
A project running more than one *front-end* theme calls `MenuLocations`
with an explicit `$theme` argument from its own code for the themes this
form doesn't cover.

Saved into config object `drupal_kit.menu_locations`, keyed by theme first —
two themes on one site (a default theme and an admin theme, or a multi-brand
site) can declare the same slot name for unrelated content:

```yaml
locations:
  arkero:
    header_menu: main
    hamburger_menu: main
    footer_menu1: footer-company
```

An unassigned slot resolves to `NULL` and `items()` returns an empty array.

### Per-language menus (optional)

Install [`config_translation`](https://www.drupal.org/docs/8/core/modules/config-translation)
(a core module, `drush en config_translation`) and a **Translate** local
task appears next to the menu locations form's own tabs. It lists every
enabled language; opening one shows a `<select>` per slot, pre-filled with
the default-language assignment, offering every menu on the site plus
"- None -" — the exact same choice the default-language form makes, on a
per-language override.

This is Drupal's own, native mechanism for translating configuration
(`config_translation`'s `ConfigTranslationFormBase`), not a bespoke
per-language field of this module's own — `config/schema/drupal_kit.schema.yml`
marks the slot value `translatable: true` with a `form_element_class`
(`Drupal\drupal_kit\FormElement\MenuSelect`) that renders as the same kind
of `<select>` the default-language form uses, instead of
`config_translation`'s own default (a plain textfield, wrong for a menu
machine name a translator should pick from a list). Saved as a language
config override — `language.<langcode>/drupal_kit.menu_locations` in config
sync terms — never as a second key in `drupal_kit.menu_locations` itself.

`config_translation` stays entirely **optional**. Nothing in `MenuLocations`
checks whether it is installed or behaves differently based on it —
`menuName()` and `items()` read the assignment through the injected config
factory exactly like any other config value a project might translate, and
`\Drupal\Core\Config\ConfigFactory::get()` transparently returns the
current language's override when one exists (via `language` module's own
`LanguageConfigFactoryOverride`) and the plain default-language value when
none does. A site with `config_translation` never installed sees exactly
that second case, always — no code path here treats its absence as
anything other than "no override exists".

One subtlety worth knowing: that override follows the **interface**
language (`languages:language_interface`), not the content language this
module's menu **items** vary by — core's own `LanguageConfigFactoryOverride`
declares that context, and it is set from the interface language on every
request. On a site like arkero, where content and interface language are
both negotiated from the same URL path prefix, the two are always equal in
practice and this distinction is invisible. A site where they can diverge
(a multilingual admin UI over otherwise-monolingual content, for instance)
would see the slot **assignment** follow the interface language while the
resolved menu's **items** still follow the content language — two
different translation mechanisms, each answering its own question.

### Reading a slot from a preprocess function

`MenuLocations::items()` returns a slot's items in `EntityHelper::getMenu()`'s
shape — the array a component template already expects:

```php
function mytheme_preprocess_page(array &$variables): void {
  /** @var \Drupal\drupal_kit\Services\MenuLocations $menu_locations */
  $menu_locations = \Drupal::service('drupal_kit.menu_locations');

  $collected = new CacheableMetadata();
  $header_items = $menu_locations->items('header_menu', $collected);
  $hamburger_items = $menu_locations->items('hamburger_menu', $collected);

  $header = [
    '#theme' => 'custom_component',
    '#template' => 'header',
    '#content' => [
      'menu' => $header_items,
      'hamburger_menu' => $hamburger_items,
    ],
    '#cache' => ['keys' => ['mytheme_layout', 'header']],
  ];
  $collected->applyTo($header);
  $variables['header_output'] = $header;
}
```

Pass the **same** `CacheableMetadata` instance across several `items()` calls
that feed one render element — the metadata accumulates once and applies
once, rather than needing a separate `merge()` per call.

`items()` render-caches its own build in the `render` cache bin, keyed by
theme, slot AND the resolved menu name (so a reassignment is reflected
immediately rather than serving the old menu from a stale entry). The
`languages:language_content` cache context on the entry — carried by the
menu's own cache metadata, not by the assignment — is what makes the SAME
key correctly serve a different translation per language: the entry varies
by content language because `EntityHelper::getMenu()`'s own output does, not
because of anything MenuLocations adds. A cache hit carries the same tags,
contexts and max-age the original build had, so a cached slot is
indistinguishable from a fresh one to the caller.

### Change log (optional)

A wrong menu in a header or footer changes every page of the site. With
the `menu_locations_log` flag in `drupal_kit.feature_flags` on, the
module writes one `notice` entry to the `drupal_kit` log channel for each
real change to an assignment:

```
header (en) [arkero]: main-en -> main-de, by editor (uid 5)
```

The entry names the slot, the language, the theme, the old and the new menu,
and the user. One listener covers the form, the Translate tab and a direct
config write such as `drush config:set`. A save that changes no assignment
writes nothing. A change without a logged-in user (drush, an update hook)
reads `by anonymous (cli)`. Removing a language override reads
`main-de -> (inherited)`. A slot with no menu reads `(none)`.

The flag is on by default, on a fresh install and, through an update
function, on a site that already had the module enabled. A site that does not
want the entries sets `menu_locations_log: false` in `drupal_kit.feature_flags`.
The log itself adds the IP address, the time and the request, so the module
does not add them again.

`dblog` keeps 100000 rows by default and then drops the oldest. A site that
needs a permanent record sends the `drupal_kit` channel to `syslog` or
another log service. That is site configuration, not module code.

### Migrating from a menu-block-in-a-region theme

A theme that placed a `menu_block`-family plugin in a region only to pick a
menu moves the assignment into config with a `post_update` hook, then
deletes the blocks:

```php
/**
 * Move menu-picker block config into drupal_kit.menu_locations.
 */
function mytheme_post_update_menu_locations(): void {
  $region_to_slot = [
    'header_menu' => 'header_menu',
    'hamburger_menu' => 'hamburger_menu',
    'footer_menu1' => 'footer_menu1',
    'footer_menu2' => 'footer_menu2',
    'footer_menu3' => 'footer_menu3',
  ];

  $theme = \Drupal::configFactory()->get('system.theme')->get('default');
  $config = \Drupal::configFactory()->getEditable('drupal_kit.menu_locations');
  $locations = $config->get('locations') ?? [];

  $default_langcode = \Drupal::languageManager()->getDefaultLanguage()->getId();
  $blocks = \Drupal::entityTypeManager()->getStorage('block')->loadByProperties([
    'theme' => $theme,
  ]);
  foreach ($blocks as $block) {
    $region = $block->getRegion();
    if (!isset($region_to_slot[$region])) {
      continue;
    }
    // The old block config carried one menu PER LANGUAGE. Content
    // translation replaces that: point the slot at the site's default
    // language's menu, and translate that menu's LINKS from there on —
    // never assign the other languages' separate menu copies. If those
    // copies diverged from the default (see the drift example above), that
    // divergence is now a content-translation task, not a config choice.
    $per_language_menu = $block->get('settings')['menu'] ?? [];
    $locations[$theme][$region_to_slot[$region]] = $per_language_menu[$default_langcode] ?? reset($per_language_menu) ?: NULL;
    $block->delete();
  }

  $config->set('locations', $locations)->save();
}
```

Add the theme's `menu_locations:` key to its `.info.yml` in the same
release, and remove the region-only-for-menus preprocess helper (arkero's
`arkero_region_items()` and its per-region loop) once every slot reads
through `MenuLocations` instead. Translate the KEPT menu's links for every
other language the site needs, through the normal content translation UI —
`/admin/structure/menu/manage/<menu_name>` → *Translate* on each link — and
delete the other languages' now-redundant menu copies once their content is
confirmed to be folded into the kept menu's translations.

### A site that already had drupal_kit enabled

`config/install/drupal_kit.menu_locations.yml` only runs on a fresh
`drush en drupal_kit` — a site that had the module enabled *before* this
feature shipped never gets the config object through that path, and
`drush updb` alone reports nothing pending for it (there is no schema
change). `drupal_kit_post_update_menu_locations()` is the fix: it runs on
the next `drush updb` on any site, and creates `drupal_kit.menu_locations`
(as an empty `locations: {}`) if, and only if, it does not already exist —
a fresh install, or a site that already ran this update, gets
`SKIP-EXISTS` and nothing changes. It uses `ConfigApplier` against the
module's own `config/install` directory, the same `hook_post_update_NAME()`
pattern documented above.

Both the service and the form already tolerate the config object being
entirely absent (not just empty) without this update — every read goes
through `?? []` / `?? NULL`, and Drupal's config system itself returns an
empty `Config` object for a name nothing has saved yet, never `NULL` — so a
site that has not run `drush updb` yet sees exactly the same behavior as
one that has: every slot resolves to "nothing assigned".

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
