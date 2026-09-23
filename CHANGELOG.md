# Changelog

All notable changes to this project are documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **`DisplayBase::create()` and the focal-point hash have tests** (#148) — both were gaps the tracker did not record, and both were self-concealing.

  `DisplayBase` sat at 13% line coverage while `__call` had fourteen cases. The uncovered half was the constructor and `create()`, and `DisplayBaseKernelTest` hid it: it builds its subject with `new class(...)`, listing the same thirteen arguments in the same order `create()` does, so the test carried its own copy of the ordering it was meant to protect. Reordering `create()` left it green.

  The ordering matters because `$entity_helper` is **untyped** — the type lives only in the docblock. Swapping two typed services is a `TypeError`; swapping that one is silent, and the class asks the wrong service for the rest of its life. `DisplayBase` is a base class consumers extend, so the failure would surface in their project, not here. Mutation-checked: `current_route_match` ↔ `language_manager` is caught by PHP itself, `drupal_kit.entity_helper` → `drupal_kit.media_array_builder` **only** by the new test.

  `ResizerFocalPointKernelTest` carried a docblock saying the non-empty-hash branch was "left as a follow-up". It is followed up. The hash is the cache-busting mechanism: Drupal keys a derivative by image style name, so without the suffix an editor who moves the focal point is served the old crop until someone flushes image styles — a silent regression that reads as a caching problem. The tests assert behaviour rather than recomputing `md5()` against the implementation: no crop means no suffix, two positions give two ids, the same position gives the same id, all read off the generated URL rather than by reflecting into the private helper.

## [3.1.0] — 2026-09-23

### Added

- `drupal_kit.config_applier` service (`Drupal\drupal_kit\Services\ConfigApplier`) and `drush kit:config-apply` command — creates, and optionally updates, an explicit list of config objects through the entity/config API, in dependency order. Never touches config outside the given list and never deletes anything. Create-only by default; `--update` opts a name into changing, with an optional `--expect-hash` guard against overwriting a production UI edit. `--dry-run` prints the plan without changing anything. Supports the `hook_post_update_NAME()` pattern documented in the README, so new config (e.g. a paragraph type and its fields) ships through `drush updb` on deploy — the supported alternative to `drush config:import` on stacks that never run a full config import.

## [3.0.0] — 2026-09-23

**Upgrading from 2.x.** Constraint only for most consumers: `composer require parisek/drupal-kit:^3.0`. One breaking change is visible on a site, and it needs one command.

`hook_file_download()` now returns `-1` for an anonymous visitor instead of redirecting to `/user/login` itself. Core answers that with a 403, so a site that serves private files needs a 403 handler:

```
composer require drupal/r4032login && drush en -y r4032login
```

Measured across the consuming projects at release time: `drupal-base` and `htdvere` use private files and need the module; `proficio` already had it enabled. The replacement is better than what it removes — `r4032login` preserves `destination`, so a visitor returns to the page they asked for after logging in, which the old redirect did not do.

Everything else is invisible to a consumer: the Drupal 10 floor, the `#[Hook]` migration, `hook_runtime_requirements`, the `FeatureFlags` service and the `#[Filter]` attributes all keep their existing behaviour and public names.

### Changed
- **BREAKING: `hook_file_download()` returns `-1` instead of sending a redirect** (#141) — the hook used to build a `RedirectResponse` to `/user/login` and send it from inside the hook, then return `void`. The contract is `array|int|null` (`file.api.php:34`), and core's own `FileDownloadHook` returns `-1` to deny.

  It looked like it worked because the browser followed a redirect it had already received. The sequence was wrong: `FileDownloadController::download()` collects what the hooks return, finds nothing, and throws `AccessDeniedHttpException` — **after the response has gone out**. Drupal then tried to render a 403 onto a finished request.

  **The login redirect is not this library's decision**, and the measurement said so plainly. What a site's 403 does belongs to the site, `drupal/r4032login` already does it properly with `destination` preserved, and `proficio` was already running that module while this hook redirected from underneath it. So this is a deletion from the library rather than an exception subscriber written, tested and maintained here.

  **Upgrade note.** A site that uses private files and does not add `drupal/r4032login` will show an anonymous visitor "Access denied" where it previously showed the login form. `drupal-base` and `htdvere` both use private files and both need the module; `proficio` already has it. `system.site:page.403` is empty on `drupal-base`, so there is no fallback there today.

  The hook also gets its first test. The old shape could only have been checked as a side effect on a global response, which is part of why none existed. Three kernel cases, mutation-checked, and the interesting one is that an authenticated user must produce an **empty** result array rather than `[NULL]`: `ModuleHandler::invokeAll()` guards each result with `isset()`, so NULL leaves no trace in the list core scans. Returning `0` instead would *grant* access — it survives `isset()`, `0 == -1` is false so core does not deny, and `count($headers)` then makes `FileDownloadController` serve the private file with a nonsense header.
- **The remaining core deprecations are cleared, and CI can finally fail on one** (#142).

  Five filter plugins move from `@Filter` annotations to `#[Filter]` attributes. Core emits a deprecation for every annotation-discovered plugin (`AttributeDiscoveryWithAnnotations.php:98`, removed in drupal:13.0.0). Plugin IDs are unchanged, so no consumer sees anything.

  `drupal_kit.skip_procedural_hook_scan: true`. `HookCollectorPass` then skips this module's procedural file scan entirely. It was unsafe while `drupal_kit.install` existed — `hook_requirements` would have stopped registering without a word — and #138 removed the last procedural file.

  New `FilterDiscoveryKernelTest`, because all five existing filter tests are **unit** tests that construct the class and call `process()`. They prove the transformation and say nothing about whether Drupal can find the plugin, so this conversion could have broken discovery with every one of them green — the same gap the `#[Hook]` migration had. And the failure would be silent: a text format stores filter IDs in config, so an ID that stops resolving does not error, the filter simply stops running and body text renders unprocessed.

### Fixed
- **`SYMFONY_DEPRECATIONS_HELPER` never did anything** — `symfony/phpunit-bridge` is not a dependency of this package and is not in `vendor/`, so nothing read the variable. Twenty lines of comment weighed `weak` against `disabled` against `max[total]=0`: a careful decision between settings of a mechanism that was not installed.

  That is why #137 — `REQUIREMENT_OK` removed in drupal:12.0.0 — was found by a code review rather than by CI. The guard was never there; it only looked like it was.

  Replaced with PHPUnit's own: `failOnDeprecation` on the root element, and `ignoreIndirectDeprecations` on `<source>`, whose include list is `src/`. So a deprecation this module causes fails the build and a deprecation in core or a vendor package does not. Measured before switching it on: 22 PHP deprecations in a full run, 20 of them PHP 8.4 implicit-nullable notices inside `mundschenk-at/php-typography` and 2 null-array-offset notices inside core. **None from `src/`**, so the ratchet costs nothing today.

  Its limit is written into the config rather than left to be discovered: it does **not** catch a deprecation this module raises with `@trigger_error()`, which is how Drupal raises them. PHPUnit ignores suppressed deprecations, and the switch that changes that would un-suppress core's too. Verified by mutation — an unsuppressed `trigger_error()` in `src/` exits 1, the same call with `@` exits 0.

### Changed
- **`FeatureFlags` is a service** (#139) — it was static because the supported range was `^10 || ^11`: 10.x has no `#[Hook]` registration, so every hook was procedural and a service would have had nobody to inject it into. #134 moved the floor and #135 turned all seven hooks into autowired classes, so the reason is gone. Registered as `drupal_kit.feature_flags` with the config factory injected.

  **`KNOWN_FLAGS` stays a constant.** The reason is that it is module-owned data rather than wiring: the container has no business carrying the list of flags this module declares. The list itself earns its keep because schema validation is not runtime enforcement — it runs in tests and in Config Inspector, never on a production read — so it is the only thing between a raw storage write and a "flag" no code here has heard of.

  Two arguments for it that do **not** hold are recorded in the class docblock, because an earlier version of this entry made both. A constructor argument would not let a project declare its own flags: the service is registered by this module, so changing the argument needs a ServiceProvider, and a ServiceProvider can swap the class and override the constant just as easily. And the other two opt-in patterns do not get typo protection free from PHP — the `$params` pattern reads keys with `isset()`, and a subclass that misspells a `protected bool` simply declares a new property. All three patterns are equally silent about a typo. An independent review found both errors.

  New case: the container really hands out the service. Constructing the class proves the logic and not that a hook can reach it — a misspelled service id would leave every call site fatal on a real site and every test green, because they all build their own instance.

  Nothing in production called `FeatureFlags::enabled()`, because no flag ships yet (#129 landed the mechanism empty on purpose). That made this the cheapest moment the change will ever have.

  `AGENTS.md` § Feature flags and `README.md` both described the static shape. Both are corrected — the AGENTS.md sentence is the one a future flag author follows, so it now also states that `#[Autowire(service: 'drupal_kit.feature_flags')]` is **required**: the service id is not the class name, so a bare type-hint fails the container build. `RELEASING.md` § Public API surface gains the new service id, plus `drupal_kit.vite_manifest` and `drupal_kit.schedule_announcer`, which the review found had been missing from that list already.

### Changed
- **`hook_requirements()` becomes `hook_runtime_requirements()`, and `drupal_kit.install` is deleted** (#137) — the procedural form without `#[LegacyRequirementsHook]` is deprecated in drupal:11.3.0 and removed in drupal:13.0.0, and the `REQUIREMENT_OK` / `REQUIREMENT_WARNING` constants it reported with are deprecated in drupal:11.2.0 and **removed in drupal:12.0.0**. Those constants were the one thing in this module that would have broken on 12.

  Two tools were looking and neither could see it. `phpstan-deprecation-rules` has no rule for global constants, so a deprecated `const` is invisible at level 8. And `phpunit.xml.dist` sets `SYMFONY_DEPRECATIONS_HELPER=weak`, so the suite counts core deprecations and never fails on them — which is why the 22 in every run had gone unexamined. Found by an independent doctrine review of #135, not by CI.

  The optional service is now injected instead of looked up. `\Drupal::hasService('menu.language_tree_manipulator')` asked whether the container knows the name; a nullable constructor argument asks whether this class received it, which is what `MenuTreeBuilder` actually depends on. `Requirements` is registered by hand in `drupal_kit.services.yml` rather than autowired — `@?` is how an argument says "NULL when missing", no attribute can say that, and `Hook.php` sanctions manual registration for exactly this case.

  That also makes a branch testable that never was. The old test asked `hasService()`, and a kernel container cannot answer yes for a service core does not ship, so the `RequirementSeverity::OK` path had no coverage at all. Handing the class a service is now enough. The replacement test drops the two `include_once` lines the old one needed to reach `core/includes/install.inc` for the constants — an enum needs no include.

  `drupal_kit.install` held nothing else, so it is gone, and with it the last procedural hook in the module. `phpstan.neon` and `phpcs.xml.dist` drop their file entries.

### Changed
- **The hooks are OOP now: seven `#[Hook]` classes in `src/Hook/`, and `drupal_kit.module` is gone** — `.claude/rules/drupal/drupal-modules.md` has named attribute classes the default for this stack for some time, and this module was the exception because the `^10` floor had no such registration. The floor moved, so the exception goes.

  `hook_module_implements_alter()` is not ported. It unset this module's entry and re-added it to push `form_alter` to the end of the list; `order: Order::Last` states the same thing on the method that needs it. Core deprecated the procedural hook in **11.2.0** and removes it in **12.0.0** unless it carries `#[LegacyModuleImplementsAlter]`, and it raises `E_USER_DEPRECATED` at every container build until then, so it had to go regardless of this migration.

  The migration removed a dependency nobody could see. `hook_locale_translation_projects_alter()` read `extension.list.module`, whose class core marks `@internal`. `\Drupal::service()` returns `mixed`, so the type never had to be written down and nothing ever objected. A constructor argument has to name it, PHPStan reported it immediately, and the hook now takes `ExtensionPathResolver` — public API, and already what `ViteManifest` and `TypographyExtension` in this same module take.

  New: `HookRegistrationKernelTest` asserts all seven hooks are registered and that the `.module` file is gone. A `#[Hook]` class fails by not being *found* — a renamed method or a dropped attribute leaves code that passes phpcs and PHPStan and is never called, and no other assertion in the suite would notice. Mutation-checked: breaking one attribute fails exactly one case and names the hook.

  `InterfaceTranslationsKernelTest` changed for a real reason rather than a mechanical one. It swaps the extension service for a stub mid-test, which worked because the procedural hook looked the service up on every invocation. An injected dependency binds once, so the stub now goes in before the first invoke and the hook service is dropped with it. That is the trade the migration makes, written down where the next reader meets it.

### Changed
- **BREAKING: Drupal 10 is no longer supported. The floor is `^11.4`** (#130) — `core_version_requirement` and `drupal/core` both read `^10 || ^11`, and nothing runs on 10.x. All three consuming projects (`drupal-base`, `htdvere`, `proficio`) are on core 11.4.7, and `drupal-base` is the skeleton every new project starts from, so 10.x had no user and no route to one.

  The floor was not free. `.claude/rules/drupal/drupal-modules.md` already names `#[Hook]` attribute classes in `src/Hook/` the default for this stack, and this module was the exception to our own doctrine because 10.x has no such registration. `FeatureFlags` is the concrete case: a static utility whose docblock had to explain that the reason was the supported core range, not a design preference.

  Consumers move by constraint, not by code: `^2.x` → `^3.0`, no template or call-site edits. Dropping a supported core version is breaking by definition even when nobody feels it, so this releases as **3.0.0**.

  Two things the new floor makes dead, removed here because they are dead, not because they are refactors. `InterfaceTranslationsKernelTest` carried a version fork — the 11.4 locale services when present, `locale.translation.inc` when not — and the fallback half loaded a file core deprecated in 11.4. And the `FeatureFlags` docblock stated a constraint that no longer exists.

  Migrating the eight procedural hooks to `#[Hook]` classes and turning `FeatureFlags` into an injected service are **not** in this change. Both become possible here; neither should ride a dependency bump. They land as their own PRs, with their own tests, before 3.0.0 ships.

  `drupal/core-dev` is pinned to `^11.4` too, so CI resolves against the floor this package actually promises rather than the oldest 11.x composer will accept.

## [2.5.0] — 2026-09-22

### Added
- **The multilingual sitemap branch finally has tests** — `FrontPageSitemapLinksAlterKernelTest` installs only `drupal_kit` and `system`, so all four of its cases ran the monolingual else-branch. The branch that reads a per-language `page.front` override had **no coverage at all**, which is exactly how it came to call a method that does not exist on the interface it was typed against: nothing ever executed the line. Every project this library serves is multilingual, so that untested branch is the one that runs in production.

  Four kernel cases with the `language` module installed and a Czech override: each language drops its own front page and keeps the other's (the same path survives in one language and vanishes in the other), a language with no override inherits the stored value, a link with no langcode is dropped on any match, and a surviving link loses only the alternates that point at a front page. Mutation-checked — reading `$stored` instead of the override fails three of the four, and disabling the multilingual branch fails all four.

### Changed
- **PHPStan now reads the test tree too, behind a baseline** (#132) — phpcs has always scanned `tests/`; PHPStan had not, and two tools disagreeing on what counts as code is how the interface defect in the previous entry survived. The tree reports 573 findings at level 8, so it lands as a **ratchet**: the existing count is baselined, and a new finding fails CI.

  A baseline is the right call here and was the wrong call for the hook files. There, 20 findings were a morning's work and one of them was a real defect a baseline would have buried. Here the bulk is mock-typing friction — 128 `phpunit.coversMethod` rows that are docblock shape, and `method.notFound` on mocks typed as the concrete class — which is cleanup, not a bug hunt.

  The cheap half is already done: five unit-test classes now type their mocked service properties as `MockObject&<Interface>`, which is what makes `->expects()` legible to the analyser. A mock typed as the concrete class hides a signature change behind a test that still compiles, so this is worth more than the count suggests.

- **The hooks are now statically analysed, and one of them was calling a method its type does not have** — `phpstan.neon` listed `paths: [src]`, so `drupal_kit.module` and `drupal_kit.install` had never been read by PHPStan. Those eight hooks are the code that touches core API most directly, which makes them the code most likely to go stale when core deprecates something, and nothing was looking at them.

  With them in scope, the analysis found a latent defect: `drupal_kit_simple_sitemap_links_alter()` called `getLanguageConfigOverride()` on `LanguageManagerInterface`, which has no such method — it belongs to `ConfigurableLanguageManagerInterface`, provided only by the language module. The guard was `isMultilingual()`, which agrees with that condition on every real site but is not the same statement, so the call was correct by coincidence rather than by type. It now narrows with `instanceof`.

  The hooks also gain scalar parameter and return types. Array *value* types are deliberately not added: PHPStan asks for `@param array<string, mixed>`, and Drupal's own standard forbids exactly that on a hook implementation ("Hook implementations should not duplicate @param documentation"), because the canonical documentation lives in core's `*.api.php`. The standard wins — core's own hooks carry no such annotation — and `missingType.iterableValue` is ignored for those two files with the reason written down.

  `phpstan/phpstan-deprecation-rules` is now installed, so a deprecated core call becomes a CI failure rather than something discovered at the next major. It reports **nothing today**: the module uses no deprecated API.


## [2.4.0] — 2026-09-22

### Added
- **Hook-level behaviour can now ship opt-in** (#115) — AGENTS.md § Feature flags requires new behaviour to default off, and documented two ways to say so: a `protected bool` on a consumer-subclassed base class, and a `$params` key on a container service. A module-level hook has neither. Nobody subclasses it and nobody passes it arguments, so a hook could only be always on, which the policy forbids, or left unshipped, which pushes the same wiring into all nineteen consuming projects. The gap was found while reviewing #114 and had no answer at the time.

  The third way is `drupal_kit.feature_flags`, a dedicated config object of **declared** booleans — the shape core itself uses for `system.feature_flags` — read through `FeatureFlags::enabled()`. Each flag is `requiredKey: false`, so a site whose export predates it stays valid, and the object is `FullyValidatable`, so an undeclared key is an error rather than a typo nobody notices.

  `FeatureFlags` also holds an allowlist of the flags this module declares. Schema validation runs in tests and in Config Inspector, never on a production read, so without it a raw config write could turn on a "flag" no code here has heard of. The allowlist is what makes *unknown flags are off* true at runtime rather than only on paper.

  No flag ships yet, and the config object does not exist yet either: Drupal skips a `config/install` file with no keys, so an empty object is unshippable. The first flag brings the object, its `false` default, a `post_update` that reaches sites installed earlier, and both test branches.

## [2.3.0] — 2026-09-21

### Added
- **`|resizer` accepts an orientation map beside its positional tuples** (#125) — a hero that is landscape on one page and portrait on the next needed a different crop per orientation, and a template could not express that. It had to classify the image itself, which no Twig template can do, or ship one crop and let the other one look wrong.

  ```twig
  {{ image|resizer({
    landscape: [['960', '720', '1280', 'crop'], ['480', '360', '', 'crop']],
    portrait:  [['720', '960', '1280', 'crop'], ['360', '480', '', 'crop']],
    square:    [['800', '800', '1280', 'crop'], ['400', '400', '', 'crop']],
  }) }}
  ```

  The shape decides which path runs: one argument, an array, carrying at least one of `landscape`, `portrait` or `square` **whose value is a list of tuples**. Everything else is positional tuples, so every existing call behaves exactly as before — the feature is opt-in per call, without a flag. The value test is what keeps a caller's own labelling safe: the old code iterated every entry, so `['landscape' => [100, 50, 900, 'crop'], …]` was a legal way to name positional tuples, and reading the key alone would explode that one tuple into four.

  Dimensions are read as floats. An int cast moves an image across the band — 1000.9 x 900.1 is landscape by its own numbers and square once truncated — and it collapses two very different sides to the same `PHP_INT_MAX` when a value exceeds it.

  An image is square while its sides differ by no more than 10 %, measured against the longer side and inclusive at both edges. The band is a class constant, not a setting: this module reads other modules' config and has none of its own, and a consumer that needs a different band classifies the image itself and passes tuples.

  **An image with no dimensions is landscape, and that is the ordinary case.** `MediaArrayBuilder` fills width and height only when the file exists and is a valid image, so a remote file behind stage_file_proxy, a missing file, or a broken one reaches the resizer with neither key. Treating that as an edge case would leave the most common production shape undefined.

  A matched bucket that is absent or empty falls through to `landscape`, so a map carries only the orientations that actually differ.

  The same call shape already exists in [`parisek/timber-kit`](https://github.com/parisek/timber-kit) and in the styleguide preview, so one template now renders identically on WordPress, on Drupal and in the preview.

### Fixed
- **CI no longer fails on two baseline ignores that depend on the core version** (#127) — every run failed at `phpstan analyse`, on both PHP 8.3 and 8.4, with `Access to an undefined property Drupal\media\MediaInterface::$thumbnail` and `…::$field_media_image` reported as unmatched ignores in `MediaArrayBuilder.php`. PHPUnit passed in the same job, so a red check read as a test failure and was not one.

  The baseline was correct for one environment and wrong for the other at the same time. Against the locked `drupal/core` phpstan still reports both errors, so the entries are needed; CI scaffolds Drupal fresh, a newer core resolves both properties, and `reportUnmatchedIgnoredErrors` turns the unused ignores into failures. Nothing changed in this repository — the last green run on `main` simply predates the core release that flipped it, so every PR opened after it went red.

  Both entries now carry `reportUnmatched: false`, which states what is true of them: the suppression holds only while core leaves those properties undeclared. Pinning core in CI was rejected, because testing against the newest core is one of the reasons that job exists, and switching `reportUnmatchedIgnoredErrors` off globally was rejected because it would stop policing every other entry in a 1400-line baseline to fix two.


## [2.2.0] — 2026-09-10

### Added
- **The module ships its own interface translations** (#123) — every string is wrapped in `t()` or `TranslatableMarkup`, so it was translatable in principle. In practice nobody translated it and every site showed English, including the message above an unpublished page on a site whose default language is Czech, and the abbreviated weekday names in `EntityHelper::getOfficeHours()`, which are user-facing content on a contact page rather than an admin screen.

  `drupal_kit.info.yml` now declares the module as its own translation project, and `translations/` carries `cs.po`, `sk.po`, `de.po` and `pl.po` covering the 28 strings the module owns. Drupal's locale module picks them up on `drush locale:update`.

  The server pattern ends `%language.po`, with no closing percent. `%language` is the whole placeholder — `%language%.po` resolves to `cs%.po`, a file that does not exist, and the import then reports the project as checked while silently importing nothing.

  Seven strings carry `['context' => 'Abbreviated weekday']` and their entries carry the matching `msgctxt`, so they do not collide with the unqualified `Mon` that core already translates.

  Three generic words the module emits — `Advanced`, `Available`, `Not available` — are deliberately **not** translated here. Locale stores a string globally by source and context rather than per project, and core emits the same three untagged, so shipping a translation for them would overwrite core's on every import and flip back on the next core update. Core already translates them.

  The install path is not assumed. `drupal_kit_locale_translation_projects_alter()` rebuilds the server pattern from the extension list, so a consumer whose `installer-paths` put the module outside `modules/contrib` still gets its translations instead of a project reported as checked with nothing imported.

  The scanner that guards the catalogue is held to account by its own test. It unescapes per quote style — a single-quoted PHP literal knows only `\'` and `\\`, so running the double-quoted rules over one turns a literal backslash-n into a newline and silently renames the string — and it reads a `context` key written either way. A shape it cannot read is a false green: the parity check reports a complete catalogue while the string ships untranslated.

  A consumer still overrides any string in Admin → Translate interface. A shipped translation is a default, not a lock.

- **A Scheduler publish or unpublish date is announced on the entity page** (#121) — `drupal_kit_page_attachments_alter()` already says *This page has not been published yet, only privileged users can see it.* When [Scheduler](https://www.drupal.org/project/scheduler) holds that page for a date, the message stopped short: it said the content was invisible, not that a date was set and cron would act on it. An editor could not tell a planned article from a forgotten draft without opening the edit form. Scheduler names the date once, in the message after the entity form is saved, so an editor who opens the page a week later saw nothing.

  The hook now adds *Scheduler publishes this content on @date.* and *Scheduler unpublishes this content on @date.*, after the existing message so the two read as one thought.

  Nothing changes on a site without Scheduler, and nothing changes on content that carries no date — `drupal/scheduler` is a `suggest`, never a dependency. The new `drupal_kit.schedule_announcer` service holds the logic and returns text; the caller decides where it goes and the theme decides how it looks, which is the split the existing message already had.

  The viewer must pass `access('update')` on the entity. An `unpublish_on` date leaves the entity published, so an anonymous visitor reaches the page, and the schedule is editorial information. The check is edit access rather than a permission name because Scheduler names its permission per entity type, and this hook serves nodes, taxonomy terms and commerce products alike.

  A date left on a bundle whose scheduling was switched off is not announced. Scheduler's base fields belong to a whole entity type rather than to the bundles that opt in, so such a value sits in the field forever and cron never acts on it.

  Scheduler's own `view scheduled <type>` permission also opens the message, alongside edit access. That permission exists for a read-only reviewer role, which by definition has no edit rights.

  Found on htdvere, where a future-dated article had been publishing itself immediately.

## [2.1.2] — 2026-09-09

### Fixed
- **`ViteManifest` no longer asks for a theme named `core`** (#118) — Drupal runs `hook_library_info_alter()` for the `core` pseudo-extension, which is neither a module nor a theme. `extensionRoot()` decided the type as module-else-theme, so every library-discovery cache rebuild asked the resolver for a theme called `core`. The resolver reports the miss with `trigger_error()`, a warning rather than an exception, so the `catch (\Throwable)` beside it never ran and the NULL it returned reached `dirname()`. Two log entries per rebuild, and a red error box on a site with error display on.

  `extensionRoot()` now returns NULL for `core` before deciding a type. `LibraryDiscoveryParser::buildByExtension()` carries the same module-else-theme rule and the same single exception, and every other extension name reaches core's own `getPath()` call before this hook runs, so mirroring core covers the whole class of names that can arrive here.

  Found on htdvere. The entries follow the cache rebuild rather than any route, so warming the cache with one page moves the warning to the next one and it reads as route-specific until you look twice.

## [2.1.1] — 2026-09-01

### Fixed
- **`|typography` no longer fatals on a number or a boolean** — the upstream filter's signature is `Stringable|string|null`, and only arrays were guarded, so an int reached it and raised a `TypeError`: a 500 on the whole page rather than a filter that declined. Ints, floats and booleans now pass through untouched, alongside the render arrays that already did. Passing through rather than casting is deliberate — typography is for prose, a number gains nothing from a non-breaking space, and the value keeps its type for arithmetic or a chained filter further down the template.

  The list stops there on purpose, and an object still reaches upstream. This filter is registered `is_safe => ['html']`, so what it returns is printed unescaped; passing an arbitrary object through would extend that promise to a value the extension never inspected, and Drupal prints an object carrying a `toString()` method raw. An object reaching a typography filter is a template defect and stays loud.

  Found while migrating a site off its local `custom_components` copy, whose filter cast silently: a branch count piped through `|typography` rendered there and took two pages down here. Every consumer moving to this package is one such call away from the same page, so the tolerance belongs in the filter rather than in a rule each site has to remember.

## [2.1.0] — 2026-09-01

### Added
- **`ViteManifest` resolves a content-hashed JS entry through `.vite/manifest.json`.** A library opts in with `vite_entry` (the manifest key, or `true` for the default `src/js/script.js`) and keeps declaring its real dist path; `hook_library_info_alter()` swaps in the hashed filename when a usable manifest sits beside it. Ported from `StarterBase::themeScriptFile()` in parisek/timber-kit.

  Why the entry needs a hash: lazy chunks always carried one, the entry did not, because `*.libraries.yml` names it by a fixed path and cache-busting came from Drupal's `?v=` instead. That covers the reference in the HTML but not the one the bundler emits inside a chunk — a module reachable from the entry graph and from a lazy chunk is hoisted into the entry, and the chunk imports it back as `./script.js`, unhashed and unqueried. Measured on the WordPress sibling (sloneek, 2026-08-17): 5 of 52 chunks imported the entry, `max-age` was 31536000, and a form silently stopped rendering with `does not provide an export named 'n'` — minified export names are positions in a table, so a stale entry can also answer with the wrong binding and no error.

  **This closes the correctness defect, not the double fetch.** `JsCollectionRenderer` appends a query to every unaggregated asset unconditionally, so the tag's URL and a chunk's own import remain two module identities — now with identical content. See ADR 0002, which also records why `drupal/vite` was not taken.

  `vite_entry` also accepts a map of asset path to manifest key, which a library declaring more than one JS asset needs; a bare key covering several assets is refused with a logged warning instead of rewriting one of them.

  Rewrites keep their declared position (Drupal emits a library's JS in array order, and an unset-and-append moved the rewritten asset last), and a map whose entries resolve to one built filename is refused whole rather than dropping an asset.

  Two constraints worth stating: only the asset whose filename matches the key's is rewritten (the property names one entry, and applying the key to every JS file made the rewrites overwrite each other), and the resolved name is cached with Drupal's library info, so a deploy shipping new assets must run `drush cr`.

  Backwards compatible by construction: no `vite_entry`, or no manifest, and the declared path is served unchanged. Four guards on the manifest value — resolvable inside the built directory, free of URL-significant characters, `.js` suffix, present on disk — each answering a reproduction rather than a hypothesis and each pinned by a mutation-verified test. Every rejection also logs: an opt-in that cannot do its job says so, instead of silently serving a declared path that may 404.


### Changed
- **`|typography` now typesets per language** — `TypographyExtension` hands the upstream `Parisek\Twig\TypographyExtension` a locale resolver, so the `languages:` tables shipped by `parisek/twig-typography` ^1.3 (quote style, dash convention, single-character word spacing, …) actually apply. Without a resolver the upstream `localeCandidates()` returns `[]` and that whole layer is inert: only the language-neutral house defaults ever ran, so Czech content was typeset with English curled quotes (`“ahoj”`, not `„ahoj“`) and lost the non-breaking space after single-letter prepositions that Czech typography requires. Ported from `StarterBase::typography_locale_resolver()` in parisek/timber-kit, the WordPress-side sibling.

  The resolver reports the **content** language (`LanguageInterface::TYPE_CONTENT`), not the interface language. The two diverge exactly where it matters — an editor whose account language is Czech previewing an English node would otherwise get Czech typography applied to English prose. Drupal falls back to the interface language when content language negotiation is not configured, so monolingual sites are unaffected. Same distinction timber-kit documents for `get_locale()` vs `determine_locale()`.

  **`FilterTypography` now forwards its own `$langcode`.** Drupal hands a text filter the language of the exact text being processed, and `process()` was discarding it — harmless while no language layer existed, wrong the moment one did: the filter would have typeset with the negotiated content language instead, which differs on mixed-language views, an explicitly rendered translation, mail and cron. `applyTypography()` takes an optional fourth argument for it; an empty langcode falls back to negotiation. A pinned language gets its own cache entry, so the negotiated path is unaffected.

  Note for direct instantiators: the constructor takes a fourth required argument. Container consumers are unaffected; the release doctrine excludes container-wired constructor signatures from the public API.

  Exposed as the overridable `protected TypographyExtension::localeResolver()` for sites whose language detection does not go through `language_manager`. The closure is evaluated per `applyTypography()` call rather than once at construction, so one cached upstream instance still serves every language in a request and the per-theme cache needs no language component.

  **This changes rendered output on multilingual sites and on any monolingual site whose language has a `languages:` entry — review pages before deploying.** Two regression tests pin the contract (Czech low-9 quotes reach the output; one instance typesets Czech and English differently across consecutive calls); both fail against the pre-fix constructor call.
- **Bumped the `parisek/twig-typography` floor from `^1.2` to `^1.3`** — the `languages:` layer this change depends on does not exist before 1.3, where the resolver argument would be accepted and silently ignored.

- **Docs: Packagist is the distribution channel** — the package is now published as [`parisek/drupal-kit` on Packagist](https://packagist.org/packages/parisek/drupal-kit) with the GitHub auto-sync webhook. README gains Packagist version + downloads badges and an Installation section (`composer require parisek/drupal-kit`); RELEASING.md drops the `vcs` repository entry instructions in favour of a Packagist sync-verification step and documents that the auto-created GitHub release must not be duplicated manually. Packagist serves every tag (including 1.x) under the canonical package name, so the `vcs` route is obsolete for all versions.

### Fixed
- **The sitemap no longer lists the front page twice** — `system.site` points `page.front` at a node, and simple_sitemap listed that page as both `/` and the node's own URL. Where the redirect module's route normalizer is enabled the second answers 301, so the file handed crawlers a redirect to a page it already contained. `drupal_kit_simple_sitemap_links_alter()` drops the duplicate and keeps `/`, which is what Drupal itself declares canonical on the front page.

  **`page.front` is translatable, so the filter is per language.** A site can point each language at its own node; asking `system.site` once and applying that answer to every link deleted the node matching the generation-time language while leaving the other language's duplicate in place. The hook now builds a langcode → front-page map, judges each link by its own `langcode`, and prunes `alternate_urls` per language — a surviving link must not re-advertise the withheld URL through its `hreflang` block.

  **Which entry survives is decided by what the page declares canonical, not by preference.** Metatag ships `canonical_url: '[site:url]'` for the front page as its own default, and a site that disables that group falls back to the `global` group's `[current-page:url-with-query:…]`, which on `/` resolves to `/` as well — so on any consumer running Metatag, keeping `/` agrees with the page. On a site without Metatag core declares the node's alias instead, and the two disagree; that limitation is recorded in ADR 0003 rather than argued away.

  **The hook is unconditional**, against `AGENTS.md`'s opt-in default. It only runs where simple_sitemap is installed and only fires on the configured front page, so "always on" means "on exactly where the defect is"; and a flag defaulting to off would not reach the nineteen sites that have the defect and have not noticed. Reasoning, and the narrow precedent it sets, in ADR 0003.

- **`merge_resizer()` supports optional per-viewport images** — ported from timber-kit's `StarterBase::twig_merge_resizer()` after the OPOP page-header case (optional mobile mascot variant) exposed two defects in the original implementation. (1) Empty groups are now dropped before the last-group detection: an unfilled optional image field makes `Resizer` return `[]`, and keeping it as the "last" group filtered the remaining (desktop) group down to media-qualified variants — producing a `<picture>` with no unconditional `<img>` fallback at all. (2) The non-last-group filter switches `isset($image['media'])` → `!empty(...)`: `Resizer` sets `media` to `''` for tuples without a breakpoint (and omits the key on the appended original image), so `isset()` leaked fallback-shaped desktop entries into the merged set ahead of the mobile entries, shadowing the mobile image on every viewport. Net effect: `merge_resizer(desktop, mobile)` keeps one call shape whether or not the optional image is filled — no `{% if %}` branching in templates. Two regression unit tests pin the contract (empty-group drop preserves the fallback; empty-media desktop entries are filtered when a mobile group follows). Consumers with a hand-mirrored copy in their theme's `static/index.php` (styleguide runs without Drupal) must apply the same change — done in `opopcz` and `drupal-base`.
- **PHPStan drift: `DependencySerializationTrait` vs promoted `private readonly` plugin properties** — `FilterLinks` and `FilterTypography` injected their services as constructor-promoted `private readonly` properties; `FilterBase` carries `DependencySerializationTrait`, which supports neither private nor readonly properties ([#3110266](https://www.drupal.org/node/3110266)), and a newer `phpstan-drupal` rule now fails the analysis (4 errors) on every fresh install — exactly the drift the no-`composer.lock` policy (ADR 0001) is meant to surface. Both properties are now plain `protected`, with an inline comment explaining the constraint. Full suite (347 tests), PHPStan level 8 and phpcs green.

## [2.0.0] — 2026-07-08

### Changed
- **BREAKING: module machine name renamed `custom_components` → `drupal_kit`** — completes the package rename inside Drupal: module files (`drupal_kit.info.yml` / `.module` / `.install` / `.services.yml`), hook implementations, the PHP namespace (`Drupal\custom_components` → `Drupal\drupal_kit` incl. tests), every service ID (`custom_components.entity_helper` → `drupal_kit.entity_helper`, …), `extra.installer-name`, and the dev symlink target. The module's human-readable name changes from "Component: Global" to "Drupal Kit" with a real description. Consumers moving from a site-local `custom_components` copy uninstall it and `composer require parisek/drupal-kit` + `drush en drupal_kit`; templates and site code referencing the old namespace or service IDs must be updated.
- **BREAKING: package renamed `parisek/custom-components` → `parisek/drupal-kit`** — the GitHub repository moved to [parisek/drupal-kit](https://github.com/parisek/drupal-kit) (old URLs redirect) and `composer.json` `name` follows, mirroring the WordPress-side `parisek/timber-kit` naming. Existing installs must update their `composer.json` require entry (and, until the package lands on Packagist, the `vcs` repository URL). The Drupal module machine name stays `custom_components` in this change; it is renamed to `drupal_kit` separately before v2.0.0.
- **BREAKING: Composer `type` changed `drupal-custom-module` → `drupal-module`** — the package is shared infrastructure distributed to multiple projects, not a site-local module, so `composer/installers` now places it in `web/modules/contrib/` instead of `web/modules/custom/`. Matches where the local dev symlink (`scripts/dev-link-module.sh`) and the kernel-test bootstrap already expected it.
- **`composer.json` `description` rewritten** — from the placeholder "Provides functionality for components." to a sentence that describes the package for the Packagist search listing.
- **README refreshed after 1.6.0** — PHPStan badge 5 → 8, CI badge points at the renamed repo, the Services list gains the three builders (`media_array_builder`, `menu_tree_builder`, `taxonomy_tree_builder`) and the `_xt` / `__t` / `_nt` / `_nxt` translation helpers, the core-patch note mentions the new status-report warning, and Related projects links `parisek/timber-kit`.

## [1.6.0] — 2026-07-07

### Added
- **Drupal coding standards via `drupal/coder` (phpcs)** — new `phpcs.xml.dist` running the `Drupal` + `DrupalPractice` rulesets over `src/`, `tests/` and the module files; `drupal/coder` promoted to an explicit `require-dev` entry; `composer phpcs` / `composer phpcbf` aliases; CI's `composer hygiene` job gains a phpcs step. The initial sweep fixed all 172 pre-existing violations at the source — 82 via `phpcbf`, 90 by hand (property `@var` docblocks, empty/short doc comments filled with real descriptions, comment rewraps to ≤80 chars, missing `@param` definitions, three genuinely-unused variable assignments dropped while keeping their side-effectful calls) — **zero suppressions**, no `phpcs:ignore` anywhere. Full suite (347 tests) and PHPStan level 8 stay green.
- **Release automation: `release-stamp.yml` + `release.yml`** — the two-workflow pattern from `parisek/timber-kit`. *Stamp* (manual `workflow_dispatch` with a semver input) validates the version + non-empty `[Unreleased]`, runs the full test + PHPStan suite as a release gate, stamps the CHANGELOG, commits, pushes an annotated tag and cross-dispatches *Release*. *Release* (tag push / manual re-run / cross-dispatch) builds GitHub Release notes from the tag's CHANGELOG section + the `(#N)` squash-merge PR list between tags, and marks Latest only for the highest semver. Adapted for this repo: `checkout@v4`, SHA-pinned `setup-php`, the kernel-test prerequisites (`gd`/`pdo_sqlite` + `scripts/dev-link-module.sh`) inside the stamp gate, and no registry-sync step (consumers install via `vcs` — the pushed tag is immediately consumable, per RELEASING.md). Nothing fires without a manual dispatch or tag push.
- **AGENTS.md: TDD-non-negotiable + feature-flag doctrine** — two sections ported from `parisek/timber-kit` and adapted: (1) test-first discipline stated as doctrine (failing test first, bug fixes reproduce as regression tests, lowest-tier-first with the decision tree in CONTRIBUTING.md, pristine output under the existing `failOnRisky`/`failOnWarning` PHPUnit flags); (2) behavior-changing features ship opt-in default-off — `protected bool` flags on the consumer-subclassed `ComponentBase`/`DisplayBase`, `$params`-key opt-ins on container services, breaking changes allowed only behind such opt-ins, opinionated defaults expressed downstream in `drupal-base`/site projects rather than in library defaults.
- **`docs/adr/` — Architecture Decision Records** — Nygard-triad ADRs (Context / Decision / Consequences), numbered permanently, written sparingly (hard-to-reverse + surprising-without-context + real-trade-off, all three). `docs/` is git-ignored repo-wide with only the `adr/` subtree tracked, so scratch planning docs never enter history. Ships with ADR 0001 recording the deliberate no-`composer.lock` policy (drift-detection over reproducibility, contained by the `platform.php` pin and the CI PHP matrix + hygiene job). Doctrine ported from `parisek/timber-kit`.
- **`RELEASING.md` — release doctrine** — tag-driven flow adapted to this package's no-Packagist distribution (consumers install via a `vcs` repository entry, so a pushed annotated tag is immediately consumable): semver procedure, Conventional Commits → bump mapping table, a **Public API surface** definition specific to this package (service IDs + public methods, `ComponentBase`/`DisplayBase` overridables, the Twig function/filter surface, documented data shapes; container-wired constructor signatures explicitly excluded), and a **Deprecation lifecycle** (docblock-only `@deprecated`, no runtime notices in request-serving paths, ≥ one MINOR grace period, live deprecations table — currently empty). README gains a short `## Releasing` section pointing at it. Ported from `parisek/timber-kit` and adapted.
- **Status-report warning when `menu.language_tree_manipulator` is missing on a multilingual site** (#90) — since the MenuTreeBuilder extraction, the language manipulator (shipped by the Drupal core patch from [#2466553](https://www.drupal.org/project/drupal/issues/2466553)) is an optional dependency and menu language filtering silently no-ops when the service is absent. New `hook_requirements()` runtime check in `custom_components.install` makes the gap visible on `/admin/reports/status`: on a multilingual site it reports *Available* (`REQUIREMENT_OK`) when the service exists and a `REQUIREMENT_WARNING` with a link to the core issue when it doesn't; monolingual sites get no entry (filtering is irrelevant there). Three kernel tests pin the contract: non-runtime phases report nothing, monolingual sites report nothing, multilingual sites without the service warn with the service name in the description.
- **Auto-typography translation helpers `_xt` / `__t` / `_nt` / `_nxt`** (#87) — typography-aware twins of the existing `_x` / `__` / `_n` / `_nx` Twig functions. Each translates first, then pipes the result through the `typography` filter, so editors get curly quotes / non-breaking spaces / dewidowing on translated UI strings with a one-character opt-in (`_x(…)|typography` → `_xt(…)`). Registered on `TwigExtension` with `needs_environment` (the `typography` filter is resolved from the environment at call time, so this extension stays decoupled from the sibling `TypographyExtension` that provides it) and `is_safe: ['html']` (mirrors the filter's own safety flag — no double-escaping of the markup it returns). Signatures match the WordPress originals 1:1 so the same templates render across `parisek/styleguide` (#21), `parisek/timber-kit` (#42) and Drupal: `_xt($text, $context, $domain)`, `__t($text, $domain)`, `_nt($single, $plural, $number, $domain)`, `_nxt($single, $plural, $number, $context, $domain)`. The `$domain` argument has no Drupal analogue (translations are keyed by langcode, not text domain), so it is accepted for cross-CMS parity and otherwise ignored; `__t` / `_nt` carry no context (matching WP), while `_xt` / `_nxt` forward it via `t()` / `formatPlural()` options. If the `typography` filter is absent the helpers degrade to a plain translation rather than throwing. Six unit tests assert translate-then-typography compose order, context forwarding, plural selection, the no-filter fallback, and end-to-end `is_safe` (no double-escaping) through a real Twig render. Drupal side of parisek/styleguide#21.

### Changed
- **PHPStan raised to level 8** (was 5) — the max-rigor level `parisek/timber-kit` runs. The 264 pre-existing findings are grandfathered in a regenerated `phpstan-baseline.neon` (dominant categories: missing param/return typehints ~137, missing iterable value types ~57 — routine follow-up, entry by entry); new code baselines nothing and is held to level 8. The 8 `call to undefined method object::…` findings were **fixed, not baselined** — `TaxonomyTreeBuilder` now narrows `loadTree(..., TRUE)` results with an `instanceof TermInterface` guard and `MenuActiveTrailResolver` guards `createInstance()` results with `instanceof MenuLinkInterface` — so that whole error class stays live for future typos instead of hiding in the baseline. `mglaman/phpstan-drupal` was already active via `phpstan/extension-installer` auto-discovery (no wiring change needed).
- **CI: PHP 8.3/8.4 matrix + composer-hygiene job** — the test job now runs on a `fail-fast: false` matrix of PHP 8.3 and 8.4 (coverage + the ratchet threshold stay on the 8.3 floor leg only; the 8.4 leg proves the suite passes on the newer runtime before consumers hit it). A separate `composer hygiene` job runs `composer validate --strict`, `composer audit --abandoned=report` (security advisories fail the job, abandoned transitive packages only report) and `composer normalize --dry-run`; `ergebnis/composer-normalize` joins `require-dev` + `allow-plugins` and `composer.json` is normalized once to establish the canonical shape. Pattern ported from `parisek/timber-kit` `tests.yml`.
- **CI: Conventional-Commits lint on PR titles** — new `commitlint.yml` workflow (`amannn/action-semantic-pull-request`, SHA-pinned to the v5 line) gates every PR title against the `feat/fix/docs/chore/refactor/perf/test/ci/build/revert` taxonomy that AGENTS.md documents but nothing previously enforced. PRs squash-merge with the title as the commit subject, so the title is what the future release bump-mapping reads. Scope optional. Pattern ported from `parisek/timber-kit`.
- **`composer.json`: `scripts` aliases + `config.platform.php` pin** — `composer test` / `test:unit` / `test:kernel` / `phpstan` aliases so contributors and docs stop spelling `vendor/bin/...` paths, and `config.platform.php: 8.3.0` so dependency resolution targets the package's PHP floor even on newer dev machines (the repo deliberately ships no `composer.lock`, so every `composer install`/`update` re-resolves — the pin keeps that resolution honest against the `>=8.3` requirement). Pattern ported from `parisek/timber-kit`.
- **Distribution trimmed via `.gitattributes` `export-ignore`** — `composer require parisek/custom-components` previously shipped the full tracked tree (tests/, .github/, .ddev/, scripts/, CHANGELOG, AGENTS/CLAUDE/CONTRIBUTING, phpstan/phpunit configs) into consumers' `vendor/` because the repo had no tracked `.gitattributes` — the local one is generated by `drupal/core-composer-scaffold` and was `.gitignore`d. Now a tracked `.gitattributes` export-ignores everything development-only, so the dist archive carries just the module files (`custom_components.*`), `src/`, `templates/`, `composer.json`, `LICENSE`, `README.md`. Scaffold generation of the file is disabled via `extra.drupal-scaffold.file-mapping` so it no longer collides with the tracked copy. Pattern ported from `parisek/timber-kit`.
- **Issue references stripped from source comments** — the builder docblocks (`MenuTreeBuilder`, `TaxonomyTreeBuilder`, `MediaArrayBuilder`), `TwigExtension::getResizer()` and the `custom_components.services.yml` resizer note referenced repo issue numbers (`#6`, `#44`), violating the AGENTS.md comment doctrine ("Don't reference PRs, issues, or call sites in code; those belong in commit messages / CHANGELOG"). The WHY content stays; only the issue-number pointers are gone. Version references (`removed in v1.4.0`) remain — they resolve against this CHANGELOG, not the issue tracker.
- **CI: `symfony/runtime` added to `allow-plugins`, `claude-code-review` workflow removed** — the repo intentionally ships no `composer.lock`, so CI resolves fresh dependencies every run; the current resolution (Drupal core 11.4.x / Symfony 7.4.x) transitively pulls `symfony/runtime`, whose Composer plugin was not in `config.allow-plugins`, aborting `composer install` before any test ran. Allow-listed (official Symfony package). The `claude-code-review.yml` workflow is removed — it failed on missing auth secrets and review runs on demand instead; the interactive `claude.yml` workflow stays.

### Fixed
- **`MediaArrayBuilder::buildRemoteVideo()` no-resolver fallback resolves the wrong field** (#89) — the fallback called `buildImage($media)`, which reads the media's own source field via `getSourceFieldValue()`; for an oembed remote video that is the video URL string, not a file ID, so `File::load()` failed and the `image` key came back empty instead of the `field_media_image` thumbnail. The fallback now reads `field_media_image`'s File references directly via a new protected `buildImageField()` helper that mirrors `EntityHelper::getImageField()`'s return shape (single item unwraps, multiple items return a list) minus translation handling — which is exactly what the docblock always claimed the fallback did. Two new kernel tests pin the behavior with real entities (a `file`-source media type whose source file deliberately differs from `field_media_image`): the fallback returns the thumbnail (not the source file), and its output is identical to the `EntityHelper::getImageField` resolver path. Consumers going through the `EntityHelper` facade were never affected (it always passes the resolver); only direct `custom_components.media_array_builder` consumers hit the bug.


## [1.5.0] — 2026-05-28

Coverage push. Where v1.4.0 deliberately held line coverage at the v1.3.0 baseline (53.71 %) and focused on hardening, v1.5.0 raises it to **78.42 %** — a +24.71 pp move via 19 PRs across the three planned tiers in roadmap #55 plus a follow-up metric-artefact cleanup round. No new public-API features; every change is either new tests, a metric-attribution fix on existing tests, a tiny code-fix the new tests surfaced, or a small dependency / `suggest` adjustment to activate previously-skipped contrib-gated paths. Consumer impact is zero — the documented surface is unchanged.

### Added
- **`EntityHelper::dispatchByFieldType` switch coverage — eight more field-type cases** — extends `EntityHelperFormatFieldKernelTest` with one test per remaining dispatcher case: `string_long`, `email`, `telephone`, `integer`, `decimal`, `text`, `text_with_summary`, `list_integer`. Each test attaches the field type to the `test_article` bundle, creates a node with a typical value, and asserts `formatField` returns the expected formatted output (so the switch case line + the chosen getter wrapper line are both credited). Adds `telephone` to the kernel module list (the only one that needs a non-core extension; the rest live in already-enabled modules). Pushed `EntityHelper` coverage past the 80 % aggregate target with no scope creep — every test maps to a distinct dispatcher branch.
- **`Resizer` focal_point branch + format-detection coverage** — new `ResizerFocalPointKernelTest` enables `crop` + `focal_point` (already in `require-dev`) and pulls in the previously-uncovered branches in `addCropEffect`'s focal_point path and `getFocalPointHash`. Plus a tiny annotation fix on the existing `testLocalFileProducesVariantsViaImageStyle` to credit `getOutputFormat` + `getFocalPointHash` to the test that actually triggers the one-time format detection (subsequent tests hit the static-cache early-return, so they can't be credited). `Resizer` coverage jumps 80 % → 90 %. Static-state reset via reflection in setUp so the toolkit-detection body in `getOutputFormat` runs fresh inside this test class.
- **Small-wins coverage round (ComponentBase / FilterTypography / FilterLinks / TypographyExtension)** — four class-level metric fixes:
  - `ComponentBase::create()` factory coverage via a new minimal `ComponentBaseStub` concrete subclass (the existing kernel tests instantiated an anonymous class so the factory body never ran). 76.6 % → 100 %.
  - `FilterTypography::create()` coverage via a new unit test that mocks the container and asserts `custom_components.typography_twig_extension` is requested. 63 % → 100 %.
  - `FilterLinks::__construct` credit via adding `@covers ::__construct` on the existing `create()` factory test (the constructor body ran in `setUp()` but no test method declared coverage of it). 96 % → 100 %.
  - `Twig\TypographyExtension::upstreamForActiveTheme` credit via adding `@covers ::upstreamForActiveTheme` to four existing applyTypography-with-string tests that already exercised the private helper transitively but only declared `@covers ::applyTypography`. 53 % → 95 %.
- **`MenuActiveTrailResolver` @covers annotations for private helpers** — four existing unit tests (`testFallbackUsesDeepestBreadcrumbMatch`, `testTrailIncludesMenuParents`, `testPrefersShallowestMatchAmongDuplicates`, `testSkipsUnroutedBreadcrumbCrumbs`) exercise `pickShallowest` + `buildTrailFromLink` transitively from `getActiveTrailIds`, but PHPUnit's strict `@covers ::publicMethod` filter only credits lines inside the explicitly-listed method. Adding `@covers ::pickShallowest` + `@covers ::buildTrailFromLink` to those four tests so the executed lines in the private helpers are correctly attributed. `MenuActiveTrailResolver` coverage rises 52 % → 98 % (39/40 lines) without writing new tests — only the coverage attribution changed.
- **`MenuTreeBuilder` direct kernel coverage** — new `MenuTreeBuilderKernelTest` exercises the builder directly (bypassing the `EntityHelper::getMenu` facade) with `@coversDefaultClass` on `MenuTreeBuilder` itself, so PHPUnit's coverage metric credits the executed lines to the builder rather than the facade. Seven tests: empty menu + `collectCacheMetadata` shape, flat menu with documented 8-key item shape (`id`, `title`, `description`, `url`, `attributes`, `is_active`, `in_active_trail`, `below`), nested-menu recursion, disabled-link filtering, `params['root']` subtree scoping, `field_formatter` enrichment of `field_*` fields under `field_`-prefix-stripped keys, and the collect-and-reset contract on `collectCacheMetadata`. `MenuTreeBuilder` coverage jumps 33 % → 89 % — the previous low number was a metric artefact of the `EntityHelperMenuTest` indirection.
- **`EntityHelper` private dispatch + mapping helpers coverage** (#60) — new `EntityHelperMapFieldsKernelTest` reaches through the public `mapFields` entry into the previously-uncovered private branches that the string-mapping tests in `EntityHelperFormatFieldKernelTest` didn't cross. Eight kernel tests: `mapArrayConfig` patterns (custom-params single-field, sub-object map, list-array passthrough, explicit `method` override), `mapFields` value-type branches (Closure invoked with entity, scalar passthrough), `mapDotNotation` against a real entity_reference→taxonomy_term field (extracts `tags.name` from each referenced term), and `mapStringConfig` unresolved-string literal-return path. The `entity_reference_revisions` paragraph branch of `mapStringConfig` is left contrib-gated like the other paragraph-style code paths. Part of v1.5.0 Tier 2 (#55).
- **`Resizer` post-#44 coverage audit + effect-builder tests** (#62) — root-cause for the v1.3.0 → v1.4.0 Resizer coverage drop (47 % → 33 %): the #44 static-only refactor (commit `1c3546e`) grew the file from 178 → 368 lines by extracting five private effect-builder helpers (`addImageEffects`, `addCropEffect`, `addSmartCropEffect`, `addCanvasEffect`, `addScaleEffect`) + a cached `getOutputFormat` lookup. The pre-#44 `image_style: 'default'` test path only ever exercised the `addScaleEffect` branch via the match dispatcher; the three other image-style branches landed without tests. Three new kernel tests (`testCropVariantBuildsImageScaleAndCropEffect`, `testSmartCropVariantBuildsEntropyEffect`, `testCanvasVariantBuildsScaleAndCanvasEffects`) each invoke `Resizer::resizer` with one of `crop` / `smart_crop` / `canvas` against a 1×1 PNG fixture and assert the fallback-tail invariant. focal_point-enabled crop branch left uncovered intentionally — adding the module to the kernel boot is disproportionate; the fallback `image_scale_and_crop` branch is the safer default and *is* covered. Part of v1.5.0 Tier 2 (#55).
- **`MediaArrayBuilder::buildSvg` happy-path + null-safe coverage** (#56) — three kernel tests covering the documented return shape (`src` / `type` / `alt` / viewBox-derived `width` + `height`), the missing-file null-safe path, and the malformed-SVG path that drops dimensions but preserves the rest of the shape. Part of v1.5.0 Tier 1 (#55).
- **`TwigExtension` missing filter/function coverage** (#57) — four unit tests for the previously-uncovered public API: `getOptionLabel` (resolves `allowed_values` map for a field item), `getCountryName` (returns the `CountryManager::getStandardList()` entry as `TranslatableMarkup`, asserted via `getUntranslatedString()` to stay hermetic), `getTranslation` (verifies the `__` / `_x` Twig functions build a `TranslatableMarkup` with `context` set), and `getResizer` (facade test that delegates to the static `Resizer::resizer()` via the SVG passthrough path). Setup now installs a `string_translation` container stub so `t()`-built `TranslatableMarkup` objects don't reach for a global container during assertions. Part of v1.5.0 Tier 1 (#55).
- **`EntityHelper::generateMedia*` / `generateFile*` dispatch coverage** (#58) — new `EntityHelperGenerateDispatchTest` unit-tests all nine thin-delegate facade methods (`generateMediaRemoteVideo`, `generateMediaVideo`, `generateMediaDocument`, `generateMediaSvg`, `generateMediaLottie`, `generateMediaImage` — split into default-empty-style + named-style cases, `generateFileImage`, `generateMediaImageLink`, `generateFileImageLink`) with a mocked `MediaArrayBuilder`, verifying each forwards to the right builder method with the expected args. `generateMediaRemoteVideo` additionally asserts the resolver callable is `[$entityHelper, 'getImageField']` (not just any callable) — the `image_field_resolver` callback that preserves i18n behaviour. Part of v1.5.0 Tier 1 (#55).
- **`EntityHelper` remaining public hot-paths** (#59) — closes coverage on the four uncovered non-contrib-gated public methods. `EntityHelperCacheAndSvgFacadeTest` (unit) covers `addCacheTags` + `collectCacheMetadata` (asserts tags bubble through the accumulator and that `collectCacheMetadata` resets internal state — the "collect and reset" contract) and the `getSvgViewBoxDimensions` facade dispatch into `MediaArrayBuilder` (forward + NULL passthrough). `EntityHelperSelectFieldOptionsKernelTest` covers `getSelectFieldOptions` end-to-end against a real `list_string` field storage (allowed_values map, `field_` prefix normalization, missing-field empty return — `language` module enabled for `ConfigurableLanguageManager::getLanguageConfigOverride`). `EntityHelperMenuFieldKernelTest` covers `getMenuField` against a real `entity_reference` field with `target_type: menu` and a populated menu_link_content menu (happy path + missing-field FALSE signal). Part of v1.5.0 Tier 1 (#55).

### Changed
- **Activate `commerce` + `address` contrib-gated tests** (#64) — adds `drupal/commerce: ^3.0`, `drupal/inline_entity_form: ^3.0@RC`, `drupal/address: ^2.0` to `require-dev`, and surfaces `drupal/address` alongside the existing `drupal/commerce` / `drupal/office_hours` entries in `composer.json`'s `suggest` block so consumers discover the optional capability without reading the test suite. Two `EntityHelperContribGatedFieldsKernelTest` tests previously `markTestSkipped` for missing modules — `testGetPriceFieldRequiresCommerceModule` (commerce_price submodule) and `testGetAddressFieldRequiresAddressModule` — now execute and assert their real dispatch paths. Per-package stability override (`^3.0@RC` on `inline_entity_form`) avoids lowering the project-wide `minimum-stability`; commerce 3.x is the only line that supports Drupal 11 and it pulls IEF at RC. The three remaining gated tests (`office_hours`, `geofield`, `webform`) stay skipped — out of scope for this PR, see #64. Full-suite runtime grew by roughly one minute (kernel boot loading the eight-module commerce stack); a PHPUnit matrix split between minimal and contrib-stack jobs remains available as a follow-up if the runtime budget tightens further.

### Removed
- **`TaxonomyTermController` + `entity.taxonomy_term.canonical` route override** (#65) — the controller (and its `_controller` injection in `RouteSubscriber::alterRoutes`) is gone. It rendered the term in `full` view mode with an explicit langcode, which sounds load-bearing but is exactly what Drupal core's default route already does. Core's `taxonomy.routing.yml` declares the route with `_entity_view: 'taxonomy_term.full'` — that route-default invokes `EntityViewController::view` with `'full'` as the view mode and lets `EntityRepository::getTranslationFromContext` handle the current-language translation. **Why we used to carry it**: legacy from Drupal 6/7 where the default term page was a "list of nodes tagged with this term" — pre-`entity_view` routing — and consumers had to override the controller to get a term-entity render. **Why it's no longer needed**: since Drupal 8 the default IS a `full`-view-mode entity render, configurable per-bundle via *Manage Display → Full* (and via `drupal/extra_field` for pseudo-field display plugins, which is already a hard dep of this module). **What removing it gets back**: the override silently dropped four core features by replacing the entity_view default outright — `<link rel="canonical">` + `<link rel="shortlink">` HTML head links (SEO), the `#entity_type` / `#taxonomy_term` template-context keys themes can rely on, and the `_title_callback: TaxonomyController::termTitle` core uses for the page title. All four return automatically. **Consumer impact**: zero visible change — the term page still renders in `full` view mode with whatever Manage Display + Extra Field config the consumer has set. The four restored features are additions, not behavior changes. **For consumers who genuinely need the old override** (e.g. unusual langcode-fallback chains): re-implement the controller in the consumer project and wire it in their own RouteSubscriber. The two-line `RouteSubscriber::alterRoutes` override + the controller's body is small enough to copy verbatim.

### Fixed
- **`EntityHelper::getSelectFieldOptions` honours language config overrides** (#70) — the method merged the original + translated field-storage configs on one line and then immediately overwrote the result with the original-only config on the next, so the `$langcode` argument was silently ignored. The stray reassignment is removed; `array_replace_recursive` now produces the final merged config. New `EntityHelperSelectFieldOptionsLanguageOverrideKernelTest` regression-covers the path: one test asserts a Czech override flows through (with non-overridden labels falling back to the original), the second asserts the default-langcode call against the same field is unaffected by the Czech override. This path was never exercised by tests before #59 added the default-language coverage — flagged during the Copilot review pass on PR #69.

### Coverage
Measured under PHP 8.3 (DDEV) with xdebug coverage driver: **78.42 %** line coverage (1141 / 1455 statements), up from **53.71 %** in v1.4.0 (+24.71 pp). CI `MIN_COVERAGE` floor in `.github/workflows/ci.yml` raised from 53 → 78. Per-class final state (highest first):

| Class | Lines |
|-------|------:|
| `ComponentBase` | 100.00 % |
| `Plugin\Filter\FilterImage` | 100.00 % |
| `Plugin\Filter\FilterLinks` | 100.00 % |
| `Plugin\Filter\FilterTable` | 100.00 % |
| `Plugin\Filter\FilterTypography` | 100.00 % |
| `Plugin\Filter\FilterYoutube` | 100.00 % |
| `Routing\RouteSubscriber` | 100.00 % |
| `Services\MenuActiveTrailResolver` | 97.50 % |
| `TwigExtension` | 96.75 % |
| `Twig\TypographyExtension` | 94.74 % |
| `Services\Resizer` | 90.45 % |
| `Services\MediaArrayBuilder` | 90.30 % |
| `Services\MenuTreeBuilder` | 89.33 % |
| `Services\TaxonomyTreeBuilder` | 85.96 % |
| `Services\EntityHelper` | 62.48 % |
| `DisplayBase` | 13.33 % |

Test count moved from **282 → 336** (+54 tests). Three contrib-gated tests still `markTestSkipped` for office_hours / geofield / webform (the modules aren't in `require-dev` — see *Deferred* below; commerce + address skips were activated in #64).

Two recurring metric-artefact patterns were documented in `AGENTS.md` during the push and applied repeatedly across the 19 PRs:
1. **Facade-as-default-class** — when a builder is exercised end-to-end through a facade (e.g. `MenuTreeBuilder` via `EntityHelper::getMenu`), PHPUnit credits the lines to the facade's `@coversDefaultClass` and the builder reports artificially low. Fix: dedicated test file with `@coversDefaultClass` on the builder itself.
2. **Strict `@covers ::publicMethod` filter** — private helpers called transitively from a covered public method run but aren't credited unless `@covers ::privateMethod` is added. Fix: add the missing annotations; no new test code required.

### Deferred to v1.6.0+
- **`DisplayBase` form-API** (13 % → ~80 %) — form rendering kernel-test setup is heavy (block plugin + form-state + render context). Best done in a focused v1.6.0 PR.
- **`EntityHelper::getOfficeHoursField`, `getWebformField`, `getGeoField`** — 54 + 20 + 19 = ~93 uncovered lines behind the three contrib-gated `markTestSkipped` tests (#64 split). Activating any of them needs the corresponding module in `require-dev` plus the kernel-boot runtime cost; webform especially is heavy. Worth a follow-up CI matrix split (minimal vs contrib-stack) before adding all three.
- **`EntityHelper` `mapArrayConfig` / `mapDotNotation` remaining branches** — ~60 uncovered lines across sub-object-map edge cases, dot-notation with explicit `method` keys, and the `entity_reference_revisions` paragraph branch in `mapStringConfig` (also contrib-gated).
- **`EntityHelper` legacy non-formatField getters** (`getLinkField`, `getTermField`, `getTextareaField`, `getMenuField`, `getEntityReferenceField` internal branches) — covered for the entry-point happy paths but with deep multi-condition branches the existing fixtures don't reach. Audit + targeted tests rather than blanket fixture expansion.

## [1.4.0] — 2026-05-26

Quality + forward-compat release. No new features and no behavioural changes for callers — focus is hardening of the v1.3.0 surface, static-analysis ratcheting, closing the deferred test gaps the v1.3.0 retrospective enumerated, and making local development reproducible against the same PHP version CI and production use.

### Added
- **DDEV as the canonical local environment** (#48) — `.ddev/config.yaml` pins PHP 8.3 (matches CI + production), omits the database container (kernel tests use sqlite::memory:), and ships a `ddev coverage` custom command that runs PHPUnit with `xdebug.mode=coverage` correctly (bypassing DDEV's default `debug,develop` mode which is the wrong driver for coverage collection and is significantly slower). The README's "Local development" section documents the canonical flow (`ddev start && ddev composer install && ddev exec vendor/bin/phpunit`). DDEV itself isn't a hard dependency — vanilla host-PHP 8.3 still works the same way CI does — but the pinned environment removes a class of "works on host, fails on CI" drift (composer.lock pinning to PHP-8.4-only versions when resolved against PHP 8.5, etc.). Local symlink wiring via `scripts/dev-link-module.sh` works from either environment provided you run the script inside the same environment whose path the symlinks should target.
- **Deferred kernel test coverage from v1.3.0** (#47) — four new files closing the gaps the v1.3.0 retrospective enumerated. `MediaArrayBuilderRemoteVideoTest` exercises `buildRemoteVideo`'s URL extraction (YouTube watch / short / passthrough) plus the `image_field_resolver` callback path, without touching the real `media_oembed` YouTube provider. `MediaArrayBuilderLottieTest` covers `buildLottie` happy + missing-file paths via the generic `file` source plugin. `DisplayBaseKernelTest` covers `__call` delegation, `$methodAliases` routing (`getEmailField` / `getPhoneField` → `getTextField`), and `BadMethodCallException` for unknown methods. `EntityHelperContribGatedFieldsKernelTest` covers the five contrib-gated getters (price / address / office_hours / geofield / webform) behind `markTestSkipped` guards — CI stays green on minimal stacks; the path automatically activates the moment a consumer installs the contrib module.

### Changed
- **PHPStan: 1.x → 2.x, level 1 → 5** (#45) — adds `mglaman/phpstan-drupal ^2.0` for Drupal-aware analysis (entity field magic-property access, service container types, hook signatures). `treatPhpDocTypesAsCertain: false` so PHPDoc type promises don't suppress legitimate findings. Pre-existing errors captured in a hand-curated identifier-grouped `phpstan-baseline.neon` so the gate is clean today without rewriting unrelated legacy paths. CI runs `phpstan analyse --memory-limit=2G`. Three real findings fixed in-flight (deprecated `(boolean)` cast in `EntityHelper`, stale Resizer baseline entries from the static refactor, MenuTreeBuilderTest container leak).
- **`menu.language_tree_manipulator` is now optional** (#43) — the service was a hard dependency on a contrib-or-patched core service that not every Drupal install ships. `MenuTreeBuilder` now accepts an optional 6th constructor argument (`?object $languageTreeManipulator`); the manipulator is added to the chain only when present. Service definition uses Drupal's `@?service.id` optional-reference syntax. README documents the upstream patch (drupal.org#2466553) for consumers who want the language-aware behaviour.
- **`Resizer` is fully static** (#44) — removed the `custom_components.resizer` service registration; the class has no instance state. `Resizer::resizer($images, $variants)` is now called directly from `TwigExtension`. Argument hardened with `array_is_list()`-aware defensive coercion so consumers passing a single-image associative array vs a list of image arrays both work without round-tripping through `end()`. README documents the static-utility status.
- **Test debt cleanup** (#46) — removed `testDispatchTaxonomyReference` and `testGetEntityReferenceFieldReturnsReferencedEntityData`; the polymorphic walker assertions were brittle and tested dispatch wiring that's already covered by `testGetTermFieldReturnsLabels` + `getMediaField` kernel coverage from #32.

### Documentation
- **README polish** (#50) — six-badge row (Packagist version, PHP version, Drupal compatibility, PHPStan level, License, CI status). PORTA spelt in caps consistently. Drupal references hyperlinked to drupal.org. Notes added for the language-tree-manipulator patch (#43) and Resizer's static-utility status (#44).

### Quality
- **#47 code-review follow-ups baked in before release**: dynamic-property deprecation in `MediaArrayBuilderRemoteVideoTest` resolved by mocking the concrete `Media` class (so `ContentEntityBase::__get` is stubbable via `willReturnCallback` rather than dynamic assignment); webform empty-signal assertion broadened to `assertEmpty()`; `enableModule()` install failures now convert to `markTestSkipped` instead of test errors.

### Coverage
Measured under PHP 8.3 (DDEV) with xdebug coverage driver: **53.71%** line coverage (789 / 1469 statements). The MIN_COVERAGE floor in `.github/workflows/ci.yml` remains at 53 — v1.4.0 is a hardening release, not a coverage push; the contrib-gated tests are skipped on the minimal CI stack by design. Per-class hotspots for v1.5.0 planning (lowest first):

| Class | Lines | Notes |
|-------|------:|-------|
| `Services\Resizer` | 33.15% | static refactor (#44) reshaped the surface; revisit |
| `Services\MenuTreeBuilder` | 33.33% | `renderLinks` deep paths still untested |
| `Services\EntityHelper` | 41.96% | the bulk; contrib-gated tests would lift this when run |
| `Services\MenuActiveTrailResolver` | 50.00% | known gap |
| `Twig\TypographyExtension` | 52.63% | `applyTypography` variants |
| `Services\TaxonomyTreeBuilder` | 57.89% | mostly covered |
| `Services\MediaArrayBuilder` | 81.21% | strong |
| `TwigExtension` | 91.87% | strongest |

### Deferred to v1.5.0+
- `Resizer` coverage gap (33% — surprising given the v1.3.0 #31 suite; the static refactor may have orphaned some dispatch paths).
- `MenuActiveTrailResolver` remaining 50% uncovered paths.
- `TypographyExtension::applyTypography` variants.
- `MenuTreeBuilder::renderLinks` deep paths (menu link content + custom field formatter).
- Real contrib-module coverage of the five getters (requires composer add of drupal/commerce, drupal/address, drupal/office_hours, drupal/geofield, drupal/webform — currently skipped on the minimal CI stack).

## [1.3.0] — 2026-05-26

### Added
- **Resizer kernel test suite** (#31) — first kernel-level coverage of `Drupal\custom_components\Services\Resizer`. New `ResizerKernelTestBase` + 6 tests covering the SVG passthrough, external-URL fallback, countable-input collapse, and the local-file image-style derivative path. `drupal/image_effects`, `drupal/focal_point`, `drupal/file_mdm` added to require-dev so the auto-orient + focal_point-aware crop effects can run in the kernel container.
- **EntityHelper image/file/media field-getter kernel tests** (#32) — `getImageField`, `getFileField`, `getMediaField` against real Drupal field API. New `EntityHelperMediaFieldsKernelTestBase` composes the test_article fixture with the PNG + Image-Media helpers. Includes cache-tag bubble assertion for `getMediaField`.
- **formatField polymorphic dispatch kernel tests** (#34) — one test per field-type branch (string, text_long, datetime, daterange, link, list_string, boolean, float, entity_reference) verifying the dispatch reaches the correct typed getter. Plus `formatFields` batch and `mapFields` string-config + empty-map paths.
- **ComponentBase form API kernel tests** (#35) — real `FormState` instance; verifies `buildConfigurationForm` render-array shape, default-value pre-population from configuration, and `submitConfigurationForm` persistence.

### Changed
- **CI coverage floor raised**: 45% → 53%. Coverage went from 45.88% (v1.2.0) to 53.03% (v1.3.0). The 80% target now sits in v1.4.0; the remaining gaps are MediaArrayBuilder oembed/Lottie (#33, convoluted), DisplayBase form API, and contrib-gated field getters.

### Deferred to v1.4.0
- `buildRemoteVideo` (oembed) + `buildLottie` kernel coverage (#33) — the stub-bundle approach needs a careful seam that survives without bit-rotting; better as a focused PR.
- `DisplayBase` form API — needs extra_field plugin discovery setup.
- Contrib-gated getters (`getOfficeHoursField`, `getAddressField`, `getGeoField`, `getPriceField`, `getWebformField`).
- `MenuActiveTrailResolver` remaining 50% uncovered paths.
- `TypographyExtension::applyTypography` variants.
- `MenuTreeBuilder::renderLinks` deep paths (menu link content + custom field formatter).

## [1.2.0] — 2026-05-26

### Added
- **MediaArrayBuilder kernel test safety net** (#18). New `MediaArrayBuilderKernelTestBase` with 1px-PNG fixture, image-style helper, and Media bundle creation helper. Kernel coverage for `buildImage`, `buildFileImage`, `buildVideo`, `buildDocument`, `buildImageLink`, `buildFileImageLink`, `getSvgViewBoxDimensions`. Remote-video oembed scenarios deferred to v1.3.0.
- **EntityHelper field-getter kernel test safety net** (#19). New `EntityHelperFieldsKernelTestBase` with `test_article` content type and `attachField()` / `createTestNode()` helpers. Kernel coverage for `getTextField`, `getTextareaField`, `getSelectField`, `getDoubleField`, `getBooleanField`, `getDateField`, `getDateRangeField`, `getLinkField`, `getTermField`, `getEntityReferenceField`. Image/file/media and contrib-gated getters deferred to v1.3.0.
- **`scripts/dev-link-module.sh`** (#22) — single source of truth for local + CI module wiring (creates `web/{profiles,sites,themes,libraries,modules/contrib}`, bridges `web/autoload.php` to `vendor/autoload.php`, symlinks via `find -maxdepth 1` so any future top-level module file is picked up automatically).

### Changed
- **`FilterLinks` migrated to constructor injection** (#20). Implements `ContainerFactoryPluginInterface`; `request_stack` is a constructor dependency. The `\Drupal::request()` static call and its `@phpstan-ignore-next-line` are gone. Test no longer needs `\Drupal::setContainer()`.
- **`TwigExtension::getTranslationPlural` migrated to constructor injection** (#21). Constructor adds `string_translation` as the third argument; existing args keep positions. The `\Drupal::translation()` static call and its `@phpstan-ignore-next-line` are gone.
- **`MediaArrayBuilder::getSvgViewBoxDimensions` reads via stream URI** instead of round-tripping through `fileUrlGenerator->generateAbsoluteString()` and `file_get_contents` of an HTTP URL. Behaviour-preserving in production (PHP's stream-wrapper integration reads `public://...` directly); unlocks kernel testability.
- **CI coverage floor raised**: 30% → 45%. Coverage went from 32.31% (v1.1.0) to 45.88% (v1.2.0). The 80% target from the v1.1.0 roadmap aspiration moves to v1.3.0, which needs `Resizer` kernel coverage + the deferred field getters + remaining contrib-gated paths.

### Polished
- `@phpstan-consistent-constructor` annotations on `ComponentBase` / `DisplayBase` / `TaxonomyTermController` now state explicitly that the contract is documentation-only (downstream consumers aren't in this repo's PHPStan scope).
- `phpunit.xml.dist` carries a comment explaining the `SYMFONY_DEPRECATIONS_HELPER=weak` + `failOnRisky=true` combination and the escape hatch if a contrib dep starts spamming deprecations.

## [1.1.0] — 2026-05-26

### Added
- **Self-contained CI** (#2) — composer scaffolds Drupal via `installer-paths`; module symlinked into `web/modules/contrib/custom_components/`; PHPUnit bootstraps from `web/core/tests/bootstrap.php` with sqlite in-memory. Replaces the previous host-Drupal-only test setup.
- **Kernel test suite** (#2, #4) — new `tests/src/Kernel/`. `EntityHelperKernelTestBase` plus `EntityHelperMenuTest`, `EntityHelperTaxonomyTest`, and a `SmokeTest` canary. 7 kernel tests at the v1.1.0 ship line.
- **Unit test coverage gaps closed** (#3) — 47 new unit tests for `FilterImage`, `FilterLinks`, `FilterTable`, `FilterTypography`, `FilterYoutube`, `RouteSubscriber`, `ComponentBase`, and `TwigExtension` (filters, functions, `templateExists`, `mergeResizer`, `formatDate` strtotime fallback).
- **PHPStan gate in CI** (#5) — `vendor/bin/phpstan analyse` runs at level 1; stale `@phpstan-ignore` annotations cleaned up across `EntityHelper`. `@phpstan-consistent-constructor` documented on the three plugin base classes.
- **Coverage measurement in CI** (#5) — Xdebug enabled; `--coverage-clover coverage.xml --coverage-text` emitted on every run; threshold gate enforces the floor.
- **CONTRIBUTING.md** + README testing section + CI badge (#5).
- **Three new builder services** (#6a, #6b, #6c):
  - `Drupal\custom_components\Services\TaxonomyTreeBuilder` — extracted from `EntityHelper::getTaxonomy` (+ `buildTermTree`). Provides its own `collectCacheMetadata()` accumulator.
  - `Drupal\custom_components\Services\MenuTreeBuilder` — extracted from `EntityHelper::getMenu` (+ `getMenuLinks`). Accepts an optional `?callable $field_formatter` for Menu Item Extras enrichment, breaking what would otherwise be a circular dependency with `EntityHelper::formatField`.
  - `Drupal\custom_components\Services\MediaArrayBuilder` — extracted from `EntityHelper`'s 9 `generateMedia*` / `generateFile*` methods plus `getSvgViewBoxDimensions`. Accepts an optional `?callable $image_field_resolver` for the remote-video field-reference path.

### Changed
- **`EntityHelper` is now a facade** (#6). Down from 2147 to ~1685 lines (-22%). All 38 public methods preserved; the three builder-related groups delegate to the new services with `try`/`finally` blocks so the builders' cache-metadata accumulator always drains, even on exception. Consumer code (htdvere etc.) sees no API change.
- **BREAKING:** Minimum PHP version raised from 8.1 to 8.3, matching the new upstream `parisek/twig-typography ^1.2` floor. Consumers on PHP 8.1/8.2 must upgrade their host before upgrading this module.
- **Typography filter** (`|typography` Twig filter + `filter_typography` text-format plugin) now delegates to `parisek/twig-typography ^1.2` instead of duplicating its logic. No behaviour change for callers — same filter name, same YAML path (`{active_theme}/static/typography.yml`), same defaults.
- Direct dependency on `mundschenk-at/php-typography` removed; it is now pulled transitively via `parisek/twig-typography`.
- `composer.json` `config.allow-plugins` now lists every plugin `drupal/core-dev` needs (composer/installers, drupal/core-composer-scaffold, …) — local `composer install` works without manual interaction; CI no longer needs to patch the file on the fly.

### Added (continued)
- `Drupal\custom_components\Twig\TypographyExtension` — thin Drupal wrapper that resolves the active theme path, caches the parsed config per theme, and delegates to the upstream extension. Pass-through for Drupal render arrays.

## [1.0.0] — 2026-05-24

Initial standalone release. Distributed via GitHub
(`parisek/custom-components`); installable as a Composer package.

Includes GitHub Actions CI that validates `composer.json` and verifies
the package + its `require-dev` set resolves and installs cleanly
against `packages.drupal.org/8`. PHPUnit and PHPStan are configured
locally (`phpunit.xml.dist`, `phpstan.neon`) but are only exercised
by consumer projects (e.g., htdvere) — full Drupal-bootstrap test
runs in standalone CI are deferred to a later phase.

### Module behavior

Renamed from `porta/custom_components` to `parisek/custom-components`,
licensed `GPL-2.0-or-later`, with runtime `class_exists()` /
`interface_exists()` guards in `EntityHelper` for the optional
integrations `drupal/commerce`, `drupal/office_hours`, and Drupal
core's `comment` module.
