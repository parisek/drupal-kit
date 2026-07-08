# AGENTS.md

Project instructions for AI coding assistants (Claude Code, Codex CLI, Cursor, Copilot, …). Claude Code imports it from `CLAUDE.md`. Human contributors: see `README.md`.

## Overview

`parisek/drupal-kit` is a Drupal module providing shared infrastructure (services, base classes, Twig extensions, image resizer) for sites built on the PORTA component pattern. Ships as a Composer package; consumers `composer require` + `drush en custom_components`.

Surface: `EntityHelper` facade (entity loading + field formatting), `MediaArrayBuilder` (Media/File array shapes), `MenuTreeBuilder` / `TaxonomyTreeBuilder` / `MenuActiveTrailResolver` (tree services), `Resizer` (static image variant builder), `ComponentBase` / `DisplayBase` (component plugins), `TwigExtension` / `TypographyExtension`, text-format filters.

## Configuration

```yaml
PACKAGE_NAME: "parisek/drupal-kit"
PHP_REQUIRES: ">=8.3"
DRUPAL_REQUIRES: "^10 || ^11"
TESTS_DIR: "tests"
```

## Development Commands

DDEV is the canonical local environment (PHP 8.3, sqlite memory DB, xdebug coverage driver). All test / analyse commands run inside the container — host PHP works too but DDEV pins the same version CI and production use.

```bash
ddev start                                          # boot the container
ddev composer install                               # PHP deps

# Tests
ddev exec vendor/bin/phpunit                        # full suite (~4 min, ~300 tests)
ddev exec vendor/bin/phpunit tests/src/Unit/…       # one file
ddev exec vendor/bin/phpunit --filter testFoo       # one method

# Coverage (xdebug)
ddev coverage                                       # → cov/index.html

# Static analysis
ddev exec vendor/bin/phpstan analyse --memory-limit=2G
```

After PHP changes the autoloader picks them up — no rebuild needed.

## Test layout

- `tests/src/Unit/…` — fast PHPUnit unit tests (mocks, no Drupal container).
- `tests/src/Kernel/…` — Drupal kernel tests (sqlite memory, real services).
- Shared bases: `EntityHelperKernelTestBase`, `EntityHelperFieldsKernelTestBase`, `MediaArrayBuilderKernelTestBase`, `ResizerKernelTestBase`.
- A unit test pins method-shape; a kernel test pins behavior end-to-end. Coverage from #59 onwards leans on kernel tests.

### Module-list gotchas (kernel tests)

- `string` field type lives in Drupal core (no module), but conventionally enable `text` alongside `field` for schema-validation consistency with the rest of the suite.
- `ConfigurableLanguageManager::getLanguageConfigOverride` requires the `language` module.
- `menu_link_content` requires `link`.
- Field storage config for `list_string` `allowed_values` accepts the compact `key => label` map on save; Drupal canonicalises to the `{value, label}` sequence on read.

## TDD — non-negotiable

Always work test-first. The discipline, not just the coverage:

- **Failing test first.** Write it, run it, watch it go red *for the right reason*, then write the minimal code to green. No production change lands without a test that failed before it existed.
- **Bug fixes too** — reproduce the bug as a failing test first; it doubles as the regression guard (e.g. `MediaArrayBuilderRemoteVideoFallbackTest` pins the no-resolver fallback to `field_media_image` so it can't silently drift back to reading the source field).
- **Pick the lowest tier that exercises the behavior** — unit (mocks, no container) before kernel (sqlite, real services). The decision tree and per-tier checklists live in `CONTRIBUTING.md`.
- **Keep output pristine.** `composer test` must stay green with `failOnRisky` / `failOnWarning` (already enforced by `phpunit.xml.dist`) for code you touch; don't add tests that emit output or mutate global state.

## Feature flags & breaking changes

New behavior that changes rendered output, data shapes, or anything a consumer could be surprised by ships **opt-in, default off** — never on by default. The library stays backwards-compatible; projects opt in.

- **Pattern (consumer-subclassed base classes).** Declare `protected bool $feature_name = FALSE;` on `ComponentBase` / `DisplayBase` (no such flag exists yet — this defines the shape for the first one) (docblock stating *what it changes* and *why it's opt-in*), gate the behavior on it, and cover both branches (off → old behavior, on → new) in the matching kernel test.
- **Pattern (container services).** Services like `EntityHelper` or the builders aren't subclassed by consumers — new behavior there opts in via a `$params` / `$custom_parameters` key (e.g. `['return_format' => 'array']` style), defaulting to the old behavior.
- **Breaking changes are allowed — but only this way.** A behavior-changing change may land *provided* it's behind such an opt-in and defaults to off, so existing consumers are unaffected until they flip it. No silent behavior changes on upgrade. Removing the old path later is a MAJOR release (see `RELEASING.md` § Public API surface).
- **Opinionated defaults live downstream.** `drupal-base` and site projects flip the flags in their own subclasses / call sites — that's where Porta Design's best-practice config is expressed, not in the library defaults.

## PR + Review workflow

- One branch per issue: `feat/<n>-<slug>`, `fix/<n>-<slug>`, `refactor/<n>-<slug>`, `chore/<slug>`.
- Commit subjects and PR titles follow [Conventional Commits](https://www.conventionalcommits.org/). Allowed types: `feat`, `fix`, `docs`, `chore`, `refactor`, `perf`, `test`, `ci`, `build`, `revert` — enforced on PR titles by `.github/workflows/commitlint.yml`. Scope optional (`feat(media):` and `feat:` both fine); `!` or a `BREAKING CHANGE:` footer marks a breaking change.
- Commit subject references the issue: `test(kernel): … (#N)`. Squash-merge produces `… (#N) (#PR)` — the PR title becomes the commit subject, which is why the title is linted.
- CHANGELOG entry lands in the same commit as the code — `[Unreleased]` → `### Added` / `### Fixed` / `### Changed`.
- Sequential merging when multiple PRs touch CHANGELOG: rebase the next branch onto updated `main` and resolve the `Unreleased` block before merging.
- Use `--admin` to merge when CI is green and auto-merge isn't configured. Never bypass hooks (`--no-verify`) without explicit approval.

### Review-thread resolution

After pushing a fix that addresses a specific Copilot (or human) inline review comment, resolve the corresponding thread programmatically:

```bash
# List threads on a PR (get node IDs of unresolved threads).
gh api graphql -f query='
  query($owner: String!, $repo: String!, $number: Int!) {
    repository(owner: $owner, name: $repo) {
      pullRequest(number: $number) {
        reviewThreads(first: 50) {
          nodes {
            id
            isResolved
            comments(first: 1) { nodes { path body } }
          }
        }
      }
    }
  }
' -F owner=parisek -F repo=drupal-kit -F number=N

# Resolve a thread (REST has no equivalent).
gh api graphql -f query='
  mutation($threadId: ID!) {
    resolveReviewThread(input: {threadId: $threadId}) {
      thread { isResolved }
    }
  }
' -F threadId="<thread_node_id>"
```

Resolve only threads whose underlying concern the latest commit actually addresses. If the fix is a polite disagreement (e.g. a documented false positive), leave a reply and *don't* resolve — let the reviewer or maintainer close it.

Re-requesting Copilot review programmatically is unreliable — the REST `requested_reviewers` POST succeeds with the bot login but doesn't trigger a new run. Ask the human to click *Re-request review* in the UI.

## Conventions

- **PHP**: PSR-12, strict types where the file already declares them, `final` classes where ownership allows.
- **Comments**: WHY, not WHAT — hidden constraints, subtle invariants, workarounds for specific bugs. Don't reference PRs, issues, or call sites in code; those belong in commit messages / CHANGELOG and rot in source over time.
- **PHPStan**: ratcheted to level 5 with a `phpstan-baseline.neon` for legacy debt. New errors outside the baseline fail CI.
- **Tests**: deterministic + offline. No network, no consumer site required.
