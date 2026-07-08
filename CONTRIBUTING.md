# Contributing to drupal_kit

## Running tests locally

CI is self-contained — it scaffolds Drupal via Composer and runs PHPUnit against `web/core/tests/bootstrap.php`. Locally, replicate the same layout once via the checked-in setup script (the very same script CI uses):

```bash
composer install
scripts/dev-link-module.sh
```

The script handles three things: creates the `web/{profiles,sites,themes,libraries,modules/contrib}` directory structure that Drupal's bootstrap scans, bridges `web/autoload.php` to `vendor/autoload.php` (drupal/core 11.3.x stopped shipping that file), and symlinks every top-level module file into `web/modules/contrib/drupal_kit/` via `find -maxdepth 1` so new files (a future `*.routing.yml`, `config/`, etc.) are picked up without a script edit.

After setup:

```bash
vendor/bin/phpunit                    # whole suite
vendor/bin/phpunit --testsuite unit   # fast — pure PHPUnit, no Drupal bootstrap
vendor/bin/phpunit --testsuite kernel # slower — real entity API, sqlite in-memory
vendor/bin/phpunit --coverage-text    # requires Xdebug locally
```

## Adding a test

This module uses two PHPUnit suites with different cost / fidelity tradeoffs.

**Unit (`tests/src/Unit/`)** — pure PHPUnit `TestCase` with mocks; no Drupal bootstrap. Fast (~ms per test). Use when the class under test:
- Has minimal Drupal API dependencies (pure transforms, formatters, filters).
- Can be exercised with mocked services (`createMock(InterfaceName::class)`).
- Doesn't need the entity API, plugin discovery, or the service container.

**Kernel (`tests/src/Kernel/`)** — extends `Drupal\KernelTests\KernelTestBase`; real Drupal service container, sqlite in-memory, modules + entity schemas installed in `setUp()`. Slower (seconds per test). Use when the class:
- Calls `\Drupal::entityTypeManager()`, `\Drupal::service()`, or similar non-trivially.
- Loads or saves real entities, builds menu trees, processes plugin discovery.
- Needs to assert on behavior that mocks would obscure (e.g., the actual shape of a render array after a transform chain).

### Decision tree

```
Class touches entity / field / plugin / menu / service container?
├── Yes → Kernel test (extends Drupal\KernelTests\KernelTestBase)
└── No  → Unit test (extends PHPUnit\Framework\TestCase)
```

When unsure, write a unit test first with mocks. If the mocks balloon beyond ~3 services or you find yourself stubbing call chains, switch to kernel.

### Adding a kernel test

1. Extend `Drupal\Tests\drupal_kit\Kernel\EntityHelperKernelTestBase` if the test exercises `EntityHelper`. Otherwise extend `KernelTestBase` directly.
2. Add the modules you need to `protected static $modules`.
3. In `setUp()`, call `parent::setUp()` first, then `installEntitySchema()` for each entity type. **Order matters** — entity types with `revision_user` (taxonomy_term, menu_link_content) need `user` installed first.
4. Avoid `installConfig(['module'])` unless you actually need the default config — it triggers strict schema validation that may fail on minimal stacks.
5. Use the `@group drupal_kit` annotation so the test is selectable by group.

### Adding a unit test

1. Extend `PHPUnit\Framework\TestCase`.
2. Use `$this->createMock(InterfaceName::class)` for Drupal services.
3. If the class under test calls `\Drupal::service('...')` directly, set up a stub container via `\Drupal::setContainer($containerBuilder)` in `setUp()` and tear it down in `tearDown()`:

   ```php
   protected function tearDown(): void {
     if (\Drupal::hasContainer()) {
       \Drupal::unsetContainer();
     }
     parent::tearDown();
   }
   ```

   Without `tearDown()` the container leaks into subsequent test classes in the same PHPUnit run.

## Coverage threshold

CI enforces a minimum **line coverage** threshold. The floor only goes up — never down. Raise it whenever you add a batch of tests that pushes the observed % comfortably above it. See `.github/workflows/ci.yml` for the current value.

Historical ratchet:
- v1.1.0 baseline (mocked units only): **32.31%** — floor 30
- v1.2.0 #18 media kernel tests: **40.76%**
- v1.2.0 #19 field-getter kernel tests: **45.88%** — floor 45
- v1.3.0 #31 Resizer kernel suite: **47.92%**
- v1.3.0 #32 image/file/media getters: **51.67%**
- v1.3.0 #35 ComponentBase form API: **53.03%** — floor 53
- v1.4.0 target (oembed + Lottie + DisplayBase form + contrib-gated): **65%+** intermediate

## PHPStan

CI runs `vendor/bin/phpstan analyse` at the level declared in `phpstan.neon`. Don't lower the level — fix the report.

## Commit style

This repo follows [Conventional Commits](https://www.conventionalcommits.org/):

- `feat:` for new features / behavior
- `fix:` for bug fixes
- `test:` for adding or refactoring tests
- `refactor:` for code restructuring without behavior change
- `ci:` for CI / workflow changes only
- `chore:` for routine maintenance

Subject line under 70 characters; details go in the body explaining the *why*, not the *what*.

## Pull requests

- One logical change per PR.
- Link the issue with `Closes #N`.
- Include a short test plan in the description.
- CI must pass (tests + PHPStan + coverage threshold) before merge.
