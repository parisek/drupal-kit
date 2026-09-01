# 0002. Read the Vite manifest here, rather than depend on `drupal/vite`

## Context

A Vite build hashes its lazy chunks but not, by default, its entry — because
`*.libraries.yml` names the entry by a fixed path and a fixed path cannot hold
a hash. Cache-busting comes from Drupal's `?v=` query instead.

That covers the reference in the HTML. It does not cover the one the bundler
emits inside a chunk: a module reachable from the entry graph and from a lazy
chunk is hoisted into the entry, and the chunk imports it back out as
`./script.js` — no hash, no query. The browser then holds two cache entries for
one file. Measured on the WordPress sibling (sloneek, 2026-08-17): 5 of 52
chunks imported the entry, `max-age` was 31536000, and a form silently stopped
rendering with `The requested module './script.js' does not provide an export
named 'n'`. Minified export names are positions in a table, not identities, so
a stale entry can also answer with the wrong binding and no error at all.

`parisek/timber-kit` closed this on the WordPress side with
`StarterBase::themeScriptFile()`. Drupal had no equivalent, so every downstream
site on this skeleton still names its entry by a fixed path.

There is a maintained contrib module for this: [`drupal/vite`][vite] (1.5.5,
July 2026, `^10.3 || ^11`). A library sets `vite: true` and lists SOURCE paths;
the module rewrites them from `.vite/manifest.json`. It also ships dev-server
detection with HMR, a documented DDEV setup, CDN `baseUrl`, `viteRoot`, and
per-asset opt-out.

Two other ecosystem options were reviewed and rejected earlier:
`@ueberbit/vite-plugin-drupal` generates `libraries.yml` wholesale, which would
replace the library structure downstream sites already have;
`vite-plugin-twig-drupal` addresses Storybook, not asset serving. Emulsify 7 —
the largest Drupal design system to adopt Vite — was checked directly: its
`emulsify.libraries.yml` names plain unhashed paths, `emulsify.theme` is an
empty stub, and it does not depend on `drupal/vite`. It uses Vite as a bundler
writing predictable paths and leaves cache-busting to Drupal's query. That is
safe for its asset shape (single files, no chunk graph) and is precisely the
shape this ADR exists to move away from.

## Decision

Read the manifest in this package (`ViteManifest`), and do not depend on
`drupal/vite`.

The deciding factor is how little of the module would be used. The styleguide
half of these projects already resolves its own entry through the manifest
(`parisek/styleguide` >= 1.16), so the module would serve exactly one code
path: the Drupal library rewrite. Weighed against that:

- The dev server — the module's largest feature, and the only one that could
  not be reimplemented cheaply — is unusable where the work happens. Component
  development on this stack targets styleguide fixtures, and the styleguide
  takes its assets from `styleguide.yaml`, never from Drupal libraries. A
  Drupal-side dev server would offer HMR on the pages where these projects
  iterate least.
- `vite: true` plus source paths in `libraries.yml` is a non-standard shape
  every new contributor has to learn. The opt-in here keeps the real dist path
  declared, so a build with no manifest renders exactly as written.
- The remaining logic is ~80 lines with a proven blueprint, not research.

## Consequences

- The correctness defect is closed: a hashed entry means the HTML reference and
  a chunk's own import name the same immutable file.
- **The double instantiation is not.** `JsCollectionRenderer` appends a query to
  every unaggregated asset unconditionally — `version === -1` only switches
  from `v=<version>` to the global asset query string, and there is no "no
  query" option. So `script.<hash>.js?v=…` and `script.<hash>.js` stay two
  module identities, fetched and executed twice, now with identical content.
  The WordPress sibling omits the query and closes both; matching that on
  Drupal means bypassing the asset pipeline (rendering the tag through
  `#attached['html_head']`), which is a larger change and is not taken here.
  **Choosing `drupal/vite` would not have closed it either** — it leaves
  production assets as files and manages no version.
- `isContentHashedEntryFile()` from the WordPress sibling is deliberately NOT
  ported. Its only job there is deciding when to omit the query, and Drupal
  offers no way to omit it, so the helper would be dead code.
- A bare key covers a library with one JS asset; more than one needs the map
  form. Picking the asset by comparing its filename to the key's was tried and
  is wrong: Vite names its output from the input map's KEY, so
  `input: {app: 'src/main.js'}` emits `app.js` under the key `src/main.js` and
  the two basenames never meet. A library that outgrows one asset gets a
  logged warning rather than a silent half-rewrite.
- The property is named `vite_entry`, unprefixed. Drupal reserves nothing here:
  core reads only `css`, `dependencies`, `deprecated`, `drupalSettings`,
  `header`, `js`, `license`, `moved_files`, `remote` and `version` from a
  library, and `LibraryDiscoveryParser` does not reject the keys it does not
  know — which is also how `drupal/vite` gets its own `vite:` key. Two names
  were rejected: `vite` collides with that module head-on, and `manifest`
  describes the file the value points INTO rather than the value itself, which
  is a Vite input path (`vite_entry: src/js/script.js`), and would sit
  confusingly beside that module's own `vite.manifest` sub-key in a project
  running both.
- The property is a declaration, not a discovery: `vite_entry` on a
  library whose asset is NOT that Vite entry will replace it with the manifest's
  file. Nothing can verify the claim from the outside — the manifest records
  inputs and outputs, not which library declared what — so the property means
  "this asset IS that entry" and a wrong declaration is wrong the way a wrong
  path in `js:` is wrong.
- The resolved filename is cached with Drupal's library info, so a rebuilt
  bundle needs a cache rebuild before it is served — and a deploy that removes
  the previous hashed file serves 404s until then. `drush cr` after shipping
  assets is therefore mandatory, not hygiene. The contrib module carries the
  same constraint; it is inherent to altering library info, not to this
  implementation.
- If a project later wants the dev server, this decision is cheap to reverse:
  drop the `vite_entry` property and adopt the module's shape. The
  reader has no consumers beyond the libraries that opt in.

[vite]: https://www.drupal.org/project/vite
