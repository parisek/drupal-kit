# Releasing `parisek/drupal-kit`

Tag-driven release flow. The package is **not** on Packagist — consumers install it from the GitHub repo via a `vcs` repository entry in their `composer.json`, so Composer reads git tags directly from GitHub. No registry upload of any kind.

## Prerequisites

- `main` branch is green:

  ```bash
  composer test
  composer phpstan
  ```

- `CHANGELOG.md` `[Unreleased]` section lists the changes for the new version.
- All review threads on PRs merged into this version are resolved.

## Procedure

### 1. Pick the version number (semver)

| Bump | When | Examples |
|---|---|---|
| **MAJOR** (`2.0.0`) | Breaking API change | Signature change on a public service method, removed Twig function, renamed service ID, behavior change consumers may depend on |
| **MINOR** (`1.6.0`) | Additive | New public method, new Twig function/filter, new optional service argument, new optional method parameter with a default |
| **PATCH** (`1.5.1`) | Bug fix or doc-only | Bug-only fix, perf improvement, internal refactor, doc-only change |

See § [Public API surface](#public-api-surface) for exactly what counts as "API" when deciding the bump type, and § [Conventional Commits](#conventional-commits) for how commit prefixes map to bump types.

### 2. Finalize `CHANGELOG.md`

Rename `[Unreleased]` → `[X.Y.Z] — YYYY-MM-DD` and insert a fresh empty `[Unreleased]` heading above it. Commit + push to `main`:

```bash
git checkout main
git pull origin main
$EDITOR CHANGELOG.md
git add CHANGELOG.md
git commit -m "docs(changelog): finalize X.Y.Z"
git push origin main
```

### 3. Create the annotated tag

```bash
git tag -a vX.Y.Z -m "vX.Y.Z: <one-line summary>"
git push origin vX.Y.Z
```

`-a` (annotated) is **mandatory** — lightweight tags lack the metadata Composer's VCS driver and GitHub's release UI expect. Use the actual `main` HEAD; never tag a feature branch.

### 4. GitHub release

Create a GitHub Release from the tag with notes derived from the matching CHANGELOG section (automated by `.github/workflows/release.yml` once that lands; until then):

```bash
gh release create vX.Y.Z --repo parisek/drupal-kit \
  --title "vX.Y.Z — <one-line summary>" \
  --notes "$(<release-notes.md)"
```

### 5. Verify consumer resolution

There is no registry sync to wait for — the tag is consumable the moment it's pushed. Verify from any consumer project (its `composer.json` must carry the `vcs` repository entry):

```bash
composer update parisek/drupal-kit --dry-run
```

The dry run should offer `vX.Y.Z`. Consumers pin `"parisek/drupal-kit": "^X.Y"`.

### 6. Bump consumer projects

- `drupal-base` and downstream Drupal sites (htdvere, …): `composer update parisek/drupal-kit` within the existing `^X.Y` constraint, or `composer require parisek/drupal-kit:^X.Y` on a minor/major jump.
- Each consumer needs the repository entry once:

  ```json
  "repositories": {
      "custom_components": {
          "type": "vcs",
          "url": "https://github.com/parisek/drupal-kit.git"
      }
  }
  ```

## Gotchas

- **Tag at the head of `main`.** Tagging a feature branch's HEAD before merge ships unmerged code. Always `git checkout main && git pull` first.
- **Composer's `^X.Y` is permissive.** `^1.5` accepts `1.5.0` through anything below `2.0.0` — a constraint written before the actual tag is known still works, but write `^X.Y` matching the actual minor for clarity.
- **Always annotated tags (`-a`).** Lightweight tags degrade version metadata for the VCS driver and GitHub releases.
- **Don't reuse a tag number.** Consumers' Composer caches and lock files may already reference the old object; force-updating a pushed tag produces split-brain installs. Bump the patch instead.
- **`[Unreleased]` must exist before tagging.** Future changes need a landing spot. Re-create it the moment you rename the previous one.
- **No `composer.lock` in this repo — deliberate.** CI resolves fresh dependencies every run, which surfaces dependency drift early (that is how the `symfony/runtime` allow-plugins break was caught). Don't "fix" a red CI by committing a lock; fix the underlying drift.

---

## Conventional Commits

All commit messages in this repo follow the [Conventional Commits](https://www.conventionalcommits.org/) format; PR titles are linted by `.github/workflows/commitlint.yml` because squash-merge makes the PR title the commit subject:

```
<type>[optional scope]: <description>

[optional body]

[optional footer(s)]
```

### Type → version bump mapping

| Commit type | Bump | Notes |
|---|---|---|
| `feat` | **MINOR** | New feature, new public method, new Twig function/filter |
| `feat!` or `BREAKING CHANGE:` footer | **MAJOR** | Any change that breaks the public API |
| `fix` | **PATCH** | Bug fix in existing public behaviour |
| `perf` | **PATCH** | Performance improvement, no behaviour change |
| `refactor` | **PATCH** | Internal-only; if public API changes, use `feat` or `feat!` |
| `docs` | **PATCH** | Doc-only, no code change |
| `test` | **PATCH** | Test-only, no production-code change |
| `chore` / `ci` / `build` | **PATCH** | Tooling, CI, packaging — no src change |
| `revert` | matches reverted commit | A revert of a `feat` that already shipped is itself breaking — think before downgrading |

**Breaking-change flag.** A `!` after the type (`feat!:`, `fix!:`) OR a `BREAKING CHANGE:` trailer in the commit footer both signal MAJOR, regardless of the type prefix.

**One commit, one bump.** When a PR contains both a `feat` and a `fix`, the highest bump wins (MINOR in this case). Choose the bump that reflects the PR as a whole.

**Deprecations use `feat`, not a `deprecated` type.** `deprecated` is not a Conventional Commits type and the PR-title lint rejects it. A deprecation ships alongside its additive replacement, so it is a `feat` (**MINOR**) — record it under `### Deprecated` in `CHANGELOG.md`. See § [Deprecation lifecycle](#deprecation-lifecycle).

---

## Public API surface

The bump type (MAJOR / MINOR / PATCH) is determined by whether the change touches the **public API**. The following are public API in this package:

### Service IDs and their public methods

Everything registered in `custom_components.services.yml` that consumers fetch from the container or receive via autowiring: `custom_components.entity_helper`, `custom_components.menu_tree_builder`, `custom_components.taxonomy_tree_builder`, `custom_components.media_array_builder`, `custom_components.menu_active_trail_resolver`, `custom_components.twig_extension`, `custom_components.typography_twig_extension` — plus every `public` method on those classes.

- **Adding** a service or public method → MINOR
- **Renaming/removing** a service ID or public method → MAJOR
- **Changing a public method signature** breakingly → MAJOR; adding a trailing optional parameter → MINOR
- **Constructor signatures are NOT public API** as long as `custom_components.services.yml` is updated in the same commit — the container owns the wiring. A consumer subclassing a service and calling `parent::__construct()` is on unsupported ground.

### Base classes for consumer plugins

`ComponentBase` and `DisplayBase` — consumers subclass these in their own modules. Every `public`/`protected` method and property a subclass is expected to call or override is public API.

- **Adding** an overridable method/property → MINOR
- **Changing** what an existing overridable method receives/returns, or removing one → MAJOR

### Twig surface

Functions and filters registered by `TwigExtension` / `TypographyExtension` (`component_*()`, `page_*()`, `uniqueId()`, `_x`/`__`/`_n`/`_nx` (+ `_xt`/`__t`/`_nt`/`_nxt`), `template_exists()`, `merge_resizer()`, `|typography`, `|resizer`, `|option_label`, `|country_name`, `|date`) and the `custom_component` / `custom_page` theme hooks with their template variables.

- **Adding** a function/filter/variable → MINOR
- **Renaming/removing** one, or changing its output shape → MAJOR

### Static utilities

`Resizer::resizer($images, $variants)` and any other `public static` method the README documents for direct consumer calls.

- **Adding** a static method or an accepted variant keyword → MINOR
- **Changing an existing signature or output shape** → MAJOR

### Data shapes

The documented return shapes of `EntityHelper` getters and the builders (image arrays `[{src, type, width, height, alt}, …]`, link `{url, title, attributes}`, menu items `{id, title, description, url, attributes, is_active, in_active_trail, below}`, …). Twig templates across every consumer are written against these shapes.

- **Adding** a key → MINOR
- **Removing/renaming** a key or changing its type → MAJOR

### What is NOT public API

The following change freely without a version bump beyond PATCH:

- `private` methods and properties anywhere
- `protected` internals of services (as opposed to the consumer-subclassed base classes above)
- Classes/methods tagged `@internal`
- Test code, CI config, GitHub Actions workflows, dev tooling
- Constructor argument lists of container-wired services (see above)

---

## Deprecation lifecycle

Deprecated API stays in the package for **at least one MINOR version** before removal. Removal requires a **MAJOR** bump.

### Deprecating a method, property or Twig function

1. Add a `@deprecated in X.Y — use <replacement> instead` docblock tag.
2. Keep the old behaviour working (a BC shim). **Deprecation is docblock-only — do NOT add a runtime `trigger_error(E_USER_DEPRECATED)` or `@trigger_error()` call** in any code path that runs during a normal Drupal request. These services run inside page rendering, REST responses and AJAX callbacks; a stray notice corrupts responses under strict error handlers and spams logs in production. A runtime notice is acceptable *only* in an unambiguously CLI- or test-only path.
3. Document the deprecation in `CHANGELOG.md` under `### Deprecated`.
4. Commit as `feat` (see § Conventional Commits) and release as **MINOR** — the replacement is additive.

### Removing deprecated API

1. Grep consumer projects for usages before removal: `rg '<deprecated-name>' ~/Sites/drupal8/*/web/modules/custom ~/Sites/drupal8/*/web/themes/custom`.
2. Document removal in `CHANGELOG.md` under `### Removed` with a migration note.
3. Release as **MAJOR**.

### Current deprecations

| Deprecated | Since | Removal target | Replacement |
|---|---|---|---|
| _none_ | | | |

---

## ADRs (Architecture Decision Records)

Significant design decisions land as an ADR in `docs/adr/` — see `docs/adr/README.md` for the template and the "record sparingly" criteria.
