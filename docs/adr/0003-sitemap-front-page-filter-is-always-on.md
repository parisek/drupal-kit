# 0003. The front-page sitemap filter keeps `/`, and ships always-on

## Context

`drupal_kit_simple_sitemap_links_alter()` drops the sitemap entry that
duplicates the front page. `system.site` points `page.front` at a node, so
simple_sitemap lists that page twice — as `/` and as the node's own URL.

Two questions had to be answered, and they are separate.

## Decision 1: keep `/`, drop the node's own entry

A sitemap is defined by carrying canonical URLs, so the entry to keep is
whichever one the page itself declares canonical. That is not a matter of
taste, and it is not the same answer everywhere:

| Stack | Front page declares |
| --- | --- |
| Metatag, `front` defaults enabled | `[site:url]` → `/` |
| Metatag, `front` defaults **disabled** | falls back to the `global` group's `[current-page:url-with-query:…]`, which on `/` is `/` |
| No Metatag | core builds it from `$entity->toUrl('canonical')`, which outbound alias processing turns into the node's alias |

The first row is Metatag's own shipped default — `config/install/
metatag.metatag_defaults.front.yml` sets `canonical_url: '[site:url]'` out of
the box. It is not a per-site customisation, which is what an earlier draft of
this record wrongly implied by citing one site's measured output as if it
settled the general case.

The second row matters because sites do disable that group. The fallback
happens to give the same answer, for a different reason: the token resolves
against the URL actually being requested, and the front page is requested at
`/`.

So on any site running Metatag — which is every consumer of this package
today — keeping `/` agrees with what the page declares. **The third row is a
real limitation, recorded rather than argued away**: on a site without
Metatag, canonical points at the alias while this filter keeps `/`, and the
two disagree. No such consumer exists yet. If one appears, the fix belongs in
that site's canonical, not in this filter, because a front page reachable at
`/` should not declare a different address as its own.

## Decision 2: the hook is unconditional

`AGENTS.md` says:

> New behavior that changes rendered output, data shapes, or anything a
> consumer could be surprised by ships **opt-in, default off** — never on by
> default.

This hook changes rendered output for every consumer running simple_sitemap,
so the rule is engaged. It ships on anyway, for three reasons:

1. **The trigger is already the filter.** The hook only runs where
   simple_sitemap is installed and only fires on a link whose path is the
   configured front page. A flag would have to be configured to reproduce the
   scope the code already has.

2. **What it removes is a duplicate of what it keeps.** The rule protects
   consumers from surprise — a changed page, a changed data shape, a changed
   contract. Removing the second of two URLs serving one page is none of
   those, given the retained one is what the page declares canonical
   (Decision 1).

3. **A flag defaulting to off would not have been adopted.** Nineteen sites
   carry this defect. The one that had noticed — htdvere — had already worked
   around it by excluding the node through
   `simple_sitemap_entity_overrides`: a row in a database table, invisible in
   git, invisible to review, and lost on a rebuild from configuration.
   Swapping an invisible per-site workaround for an invisible per-site flag
   is not an improvement.

Note what is **not** claimed. An earlier draft argued the opt-in rule "does
not reach" module-level hooks, because its worked pattern is a
`protected bool` on a consumer-subclassed base class and a `.module` hook has
no subclass to flip. That is true (issue #115 records the gap) but it is not
a reason — a missing mechanism is a reason to build one, not a licence to
skip the requirement. This is a deliberate exception, taken by the owner, on
the three reasons above.

## Consequences

- **The precedent is not "defect fixes are exempt".** Read that way, any
  contributor can label output "a duplicate nobody wants" and ship a
  behaviour change on. What carried the decision is reason 1: the code's own
  trigger is already as narrow as a flag would be. A fix that reaches
  consumers who do not have the defect is exactly what the opt-in rule is
  for, and this record is not cover for one.

- **A consumer wanting the duplicate listed** must remove the module or
  re-add the link in its own `hook_simple_sitemap_links_alter()`. That escape
  is ordering-dependent and therefore weak; it is the argument for adding a
  flag if such a consumer ever appears, not a reason there is no need for one.

- **`AGENTS.md` needs a sentence** on how an exception like this is taken and
  where it is recorded, so the rule and this hook do not contradict each
  other in writing. That edit is deliberately not part of this change:
  doctrine is proposed and approved on its own, not smuggled in beside the
  code that motivated it.

## Alternatives considered

**A service parameter, default off.** The mechanism exists and is used
elsewhere in this package. Rejected on reason 3.

**A hook forcing `metatag.metatag_defaults.front.canonical_url`** so canonical
and sitemap agree by construction. Rejected: Metatag already ships that value
as its default, so the hook would act only where a site had deliberately
changed it — overriding a decision someone made on purpose. It would also
make this fix depend on a module the package does not require.

**Scope the filter to sites running the redirect module's route normalizer**,
where the duplicate answers 301 rather than 200. Rejected: serving one page at
two indexed URLs is the defect either way. The 301 makes it visible, not real.
