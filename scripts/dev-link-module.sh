#!/usr/bin/env bash
#
# Wire this repo into the local web/ Drupal scaffold so PHPUnit (whose
# bootstrap is web/core/tests/bootstrap.php) can discover the module
# the same way it does in CI.
#
# Run after `composer install`. Idempotent — re-running picks up newly
# added top-level files (e.g. a future drupal_kit.routing.yml)
# without needing this script edited.
#
set -euo pipefail

repo_root="$(cd "$(dirname "$0")/.." && pwd)"
target="$repo_root/web/modules/contrib/drupal_kit"

mkdir -p "$repo_root/web/profiles" \
         "$repo_root/web/sites" \
         "$repo_root/web/themes" \
         "$repo_root/web/libraries" \
         "$repo_root/web/modules/contrib" \
         "$target"

# Drupal 11.3.10 scaffold no longer ships web/autoload.php; bridge it.
# bootstrap.php loads ../../autoload.php relative to web/core/tests/, so
# we point web/autoload.php to vendor/autoload.php one level up.
cat > "$repo_root/web/autoload.php" <<'PHP'
<?php
return require __DIR__ . '/../vendor/autoload.php';
PHP

# Symlink every top-level repo file/dir into the module discovery path,
# excluding build artifacts and meta files. Using -mindepth/-maxdepth
# 1 instead of an explicit allowlist so any future top-level file
# (routing.yml, *.install, config/, schema/, …) is picked up
# automatically — code-review nález from v1.1.0.
cd "$repo_root"
find . -maxdepth 1 -mindepth 1 \
  -not -path './.git' \
  -not -path './.github' \
  -not -path './vendor' \
  -not -path './web' \
  -not -path './node_modules' \
  -not -path './scripts' \
  -not -name '.gitignore' \
  -not -name '.editorconfig' \
  -not -name 'CHANGELOG.md' \
  -not -name 'CONTRIBUTING.md' \
  -not -name 'LICENSE' \
  -not -name 'README.md' \
  -not -name 'phpstan.neon' \
  -not -name 'phpunit.xml.dist' \
  -not -name 'composer.lock' \
  -not -name 'coverage.xml' \
  -print0 \
  | while IFS= read -r -d '' entry; do
      name="$(basename "$entry")"
      rm -rf "$target/$name"
      ln -s "$repo_root/$name" "$target/$name"
    done

echo "Module linked at $target"
