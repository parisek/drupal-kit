# 0001. Ship no composer.lock; pin the platform instead

## Context

This package is a library installed into a range of Drupal projects
(`drupal/core ^10 || ^11`), not a deployable application. A committed
`composer.lock` would give CI perfectly reproducible builds — but it would
also freeze CI onto one dependency snapshot, and this package's failures
historically come from *drift*, not from flakiness: a fresh resolution is
what surfaced the `symfony/runtime` allow-plugins break (Drupal 11.4 /
Symfony 7.4 line) before any consumer hit it. A lock file would have kept
CI green while every consumer's install was already broken.

The competing risk of floating resolution is environment skew: a dev
machine on PHP 8.4 resolves a different tree than the 8.3 floor the
package promises, and `composer audit` needs installed packages (not a
lock) to scan.

## Decision

No `composer.lock` in git (it stays in `.gitignore`). Every CI run and
fresh `composer install` resolves the latest tree the constraints allow.
The skew risk is contained from two sides:

- `config.platform.php: 8.3.0` in `composer.json` forces resolution
  against the PHP floor regardless of the machine's PHP.
- CI runs the test matrix on both supported PHP lines (8.3 + 8.4) and a
  `composer hygiene` job (`validate --strict`, `audit --abandoned=report`,
  `normalize --dry-run`) on every push.

## Consequences

- Dependency drift breaks *our* CI first, not consumers' installs — that
  is the point. A red CI caused by a new upstream release is a signal to
  fix compatibility (or constrain it), never to commit a lock and hide it.
- CI runs are not bit-for-bit reproducible across days. Debugging a
  drift-induced failure means reading the resolved versions from the CI
  log, not from a lock diff.
- `composer audit` runs against whatever resolves that day, which is
  exactly the set a consumer would get — the useful set to scan.
- Guards: `.gitignore` keeps the lock out; `RELEASING.md` § Gotchas states
  the policy ("don't fix red CI by committing a lock"); the platform pin
  lives in `composer.json` and the hygiene job fails if it's removed
  carelessly (normalize check keeps the file canonical).
