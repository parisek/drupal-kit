# 0003. The front-page sitemap filter ships always-on

## Context

`drupal_kit_simple_sitemap_links_alter()` drops the sitemap entry that
duplicates the front page. `system.site` points `page.front` at a node, and
simple_sitemap lists that page twice — once as `/` and once as the node's own
URL. Where the redirect module's route normalizer is enabled, the second
answers 301, so the file hands crawlers a redirect to a page it already
contains.

`AGENTS.md` says:

> New behavior that changes rendered output, data shapes, or anything a
> consumer could be surprised by ships **opt-in, default off** — never on by
> default.

This hook changes rendered output for every consumer running simple_sitemap.
Read literally, the rule forbids it.

The rule's own worked pattern, in the paragraph below that sentence, is a
`protected bool $feature_name = FALSE;` property on `ComponentBase` or
`DisplayBase`, flipped by a consumer subclass. A `hook_*_alter()`
implementation in `.module` has no subclass to flip anything on. There is no
documented opt-in mechanism for a module-level hook at all — which is what
issue #115 records.

So the decision is not "take an exception to the rule". It is a decision in a
place the rule does not reach, and leaving it unrecorded would leave the next
reader to conclude the rule was simply ignored.

## Decision

The hook is unconditional. No flag, no setting, no service parameter.

Three reasons, in the order they carried weight:

1. **The blast radius is already the correct filter.** The hook only runs
   where simple_sitemap is installed and only fires on a link whose path is
   the configured front page. A consumer without the module, or without a node
   behind `page.front`, cannot observe it. "Always on" therefore means "on
   exactly where the defect is", which is what a flag would have to be
   configured to reproduce.

2. **The output it removes is not output anyone wants.** The rule protects
   consumers from *surprise*, and the surprise it has in mind is a changed
   page, a changed data shape, a changed contract. Here the change is the
   removal of a URL that duplicates one the same file already lists. No
   consumer is known to want the duplicate, and a sitemap is defined by
   carrying canonical URLs.

3. **A flag defaulting to off would not have been adopted.** Nineteen sites
   carry this defect. A flag nobody flips fixes nothing, and the one site that
   had noticed — htdvere — had already worked around it by hand, excluding the
   node through `simple_sitemap_entity_overrides`. That workaround lives in a
   database table, not in `config/sync`: invisible in git, invisible to review,
   and lost on a rebuild from configuration. Replacing an invisible per-site
   workaround with an invisible per-site flag would have been no improvement.

## Consequences

- A consumer that genuinely wants the duplicate listed must remove the module
  or re-add the link in its own `hook_simple_sitemap_links_alter()`. No such
  consumer is known; if one appears, that is the moment to add the flag, and
  this record is the argument to weigh against.

- `AGENTS.md` needs a sentence saying its opt-in rule addresses consumer-facing
  API surface, and that a defect fix confined to the defect is decided
  separately. Without it, the rule and this hook contradict each other in
  writing, and the next contributor has to guess which one is stale. That edit
  is deliberately **not** part of this change: doctrine edits are proposed and
  approved on their own, not smuggled in beside the code that motivated them.

- The precedent is narrow on purpose. It says a fix whose reach is already
  limited to the broken case may ship on. It does not say defect fixes are
  exempt from the opt-in rule in general — a fix that touches consumers who do
  not have the defect is exactly what the rule is for.

## Alternatives considered

**A service parameter, default off.** The mechanism exists and is used
elsewhere in this package. Rejected on reason 3: the sites that need it are
the sites that have not noticed they need it.

**Scope it to sites running the redirect module's route normalizer**, where
the duplicate answers 301 rather than 200. Rejected: the duplicate is a
duplicate either way. Serving the same content at two indexed URLs is the
defect; the 301 makes it visible, not real.
