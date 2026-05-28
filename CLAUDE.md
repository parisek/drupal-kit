# CLAUDE.md

Shared project instructions: @AGENTS.md

`AGENTS.md` is the shared AgentMD entry point for Codex, Cursor, Copilot, and other AI coding assistants. Keep universal project rules there. This file is Claude Code-specific runtime configuration, hooks, and workflow preferences.

## Task Delegation

Spawn subagents to isolate context, parallelize independent work, or offload bulk mechanical tasks. Don't spawn when the parent needs the reasoning, when synthesis requires holding things together, or when spawn overhead dominates.

Pick the cheapest model that can do the subtask well:

- **Haiku** — bulk mechanical work, no judgment.
- **Sonnet** — scoped research, code exploration, in-scope synthesis.
- **Opus** — subtasks needing real planning or tradeoffs.

If a subagent realizes it needs a higher tier than itself, return to the parent. Parent owns final output and cross-spawn synthesis. User instructions override.

## Preferred Tools

### Data Fetching

1. **WebFetch** — free, text-only, works on public pages that don't block bots.
2. **claude-in-chrome / chrome-devtools** — only when WebFetch can't handle the page (dynamic JS, auth walls). This module has no SPA surface so browser tools are rarely needed.

### Verification

This is a Drupal module — no browser surface to verify after code changes. The verification ladder:

1. `ddev exec vendor/bin/phpunit <touched test file>` — fast feedback while iterating.
2. `ddev exec vendor/bin/phpunit` — full suite before pushing for review.
3. `ddev exec vendor/bin/phpstan analyse --memory-limit=2G` — when signatures or types changed.
4. `ddev coverage` — only when measuring a coverage push (e.g. Tier checkpoint per #55).

Do not run host PHP for tests — DDEV pins the same PHP version CI and production use, host drift produces "works on my machine" surprises.
