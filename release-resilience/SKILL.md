---
name: release-resilience
description: Pre-release audit for one question — "if this ships in an hour and nobody touches it for a year, does the inbox stay empty?" Hunts the failure classes that stay quiet in a demo and get loud unattended: deploy-provisioning gaps, failures past the point of no return, discarded error signals, un-isolated fan-outs, per-request throws on a systemic misconfig, silent disappearance, a new output sink with weaker escaping than its sibling, a privileged page missing the authz check every sibling has, unwatched scheduled/queued entry points, unchecked external-dependency assumptions, orphaned per-tenant storage. Produces a severity-ranked findings doc plus SMART stories and a build prompt. Use before merging a feature that adds or rewires a user-facing runtime path.
---

# Skill: release-resilience

A focused review lens, not a general one. `/review-panel` and `/code-review`
ask "is this code correct and secure?". This asks a narrower question:

> **We release in one hour. Then everyone goes on holiday for a year and
> nobody watches the logs. Does the mailbox stay empty?**

That reframes severity. A bug that throws once, loudly, at boot is nearly
harmless here — someone sees it on deploy day. A path that 500s on *every*
request, or fails *silently*, or floods the error tracker with one root
cause 40,000 times, is what fills the year-later inbox. Rank by **how loud ×
how often, unattended** — not by CVSS.

Run this in addition to a normal review, not instead of one.

## Inputs

`$ARGUMENTS` (optional) — same shapes as `review-panel`:
- empty: audit the current diff (branch vs its merge-base with the default branch; fall back to `git diff HEAD`)
- a PR number (`123` / `#123`): `gh pr diff <n>`
- a branch name: diff vs its merge-base with the default branch
- a path: scope to that file/dir (diff if it has pending changes, else the file content)

Print the resolved target + a one-line `--stat` before starting. If the diff
adds no runtime code path (docs/tests/config only), say so and stop.

## What counts as in scope

The audit centres on **new or rewired runtime paths a user or a schedule can
reach**: a new controller/action/route, a table/row action that went from
no-op to real work, a new command added to a schedule or queue, a new
renderer/exporter/mailer/webhook, a new storage location. Pure internal
refactors with no reachable behaviour change are out of scope for this lens.

## Step 1 — Map the new reachable paths

Before checking anything, list them. For each entry point the diff adds or
changes:
- what triggers it (HTTP route, Filament action, `$schedule->`, queue job, webhook, event listener)
- what fallible work it does (render, disk I/O, DB, external HTTP, shelling out, template resolution)
- what it depends on existing (files on a disk, seed rows, config keys, a binary, a service, a warm cache)

Grep for the call sites, don't guess: `grep -rn "ClassName\|methodName" --include=*.php | grep -v /Tests/`.

## Step 2 — Run the checklist

Each check below has a **detection recipe** (what to grep / read), a
**confirm bar** (what proves it real), and the **unattended failure story**
(why it matters for the year-later inbox). Report only confirmed items.

### R1 — Deploy-time provisioning gap
A new runtime path depends on state that no deploy step creates.
- **Detect**: for each "depends on existing" item from Step 1 (a file on a Storage disk, a seed row, a config value, a synced template, a warmed cache), grep for what populates it — a `composer.json` script, a migration, a seeder, a `$schedule`, a deploy hook, a Dockerfile step. If the only hits are test `setUp()` / the thing's own definition, the deploy never runs it.
- **Confirm**: the populating command/seeder exists, is correct, and is **wired** into an automated deploy step — not just documented in a README or an exception message.
- **Story**: fresh install → the path throws on the very first real request and keeps throwing until someone SSHes in. This is the archetypal year-later blocker.
- **Preferred fix shape**: make the path self-heal (read from the committed source when the provisioned copy is absent) so a forgotten deploy step can't break it; keep the explicit provisioning command for refresh.

### R2 — Failure past the point of no return
Fallible work runs *after* the response has started streaming.
- **Detect**: `grep -rn "streamDownload\|StreamedResponse\|->stream(\|ob_flush\|flush()" --include=*.php`. For each, check whether the closure/body does rendering, DB, external calls, or template resolution *inside* it (vs. building the payload first and streaming a ready string).
- **Confirm**: an exception in that inner work lands after `200` + headers are sent → the framework can't render an error page.
- **Story**: user gets a truncated / 0-byte file, no error shown, only a log line. "Downloads are broken for some customers and there's no trace" — the hardest kind of ticket.
- **Fix shape**: materialise the payload before returning the response; if a ready safe method exists (it often does and is unused), call it.

### R3 — Discarded failure signal + premature success
A write/call whose return value is ignored, followed by an unconditional "success" to the user.
- **Detect**: `grep -rn "->put(\|->save()\|file_put_contents\|Storage::\|Http::\|->send()" --include=*.php` in the diff; check whether the boolean/response return is captured. Then look for a `Notification::...->success()` / flash / `return response()->json(['ok'...])` on the same path with no conditional.
- **Confirm**: the operation can fail (full disk, read-only FS, 5xx from the API, permission) and on failure the user is still told it worked.
- **Story**: silent data loss. "My changes keep disappearing" — irreproducible, trust-destroying, and it accumulates quietly for a year.
- **Fix shape**: check the return; throw or convert to a `->danger()` notification; log.

### R4 — No per-item isolation in a fan-out
A loop calls N plugins / bricks / handlers / formatters / recipients with no per-iteration `try/catch`.
- **Detect**: in the diff, find `foreach` loops that call into pluggable/registered code (`$brick::toHtml()`, `$handler->handle()`, `$formatter->format()`, per-recipient send). Check for a `try` inside the loop body.
- **Confirm**: one element throwing aborts the whole batch (the whole PDF, the whole export, the whole mail run).
- **Story**: a data edge case in *one* section takes down the feature for *everyone* using that template/report/list. Systemic-outage amplifier.
- **Fix shape**: wrap each iteration; on `Throwable` emit a placeholder + `Log::warning` with the item id, continue.

### R5 — Per-request throw on a systemic misconfig
A misconfiguration (missing template, absent credential, unresolvable default) throws on *every* request to a common path instead of failing once at boot.
- **Detect**: new `throw new` on a hot path (a controller, a widely-used service method, a list/detail page). Ask: if the underlying config is wrong, does this fire once or once-per-request?
- **Confirm**: the precondition is environmental (not per-user input), so a wrong deploy makes it throw on essentially every hit.
- **Story**: the error tracker gets the same exception 40,000 times over the year; disk fills with logs; on-call (if any) is paged repeatedly for one root cause. Even with nobody watching, it's a landmine for whoever *does* eventually look.
- **Fix shape**: fail fast and loud at boot / deploy (a health check, a `php artisan` verify step) OR degrade gracefully per-request with a single rate-limited log.

### R6 — Silent disappearance
A swallowed `catch` or `?? []` makes a *broken* resource indistinguishable from an *absent* one.
- **Detect**: `grep -rn "catch (\\\\?[A-Za-z]*Exception.*) *{ *return null\|catch (.*) *{}" --include=*.php` on the diff; also `?? []` / `?? ''` right after a decode/parse/fetch.
- **Confirm**: on corruption/partial-write/parse-failure the item just vanishes from the UI with no log line.
- **Story**: operator can't tell "there are no templates" from "the template file is corrupt". Debugging starts from zero a year later.
- **Fix shape**: keep the graceful fallback, but `Log::warning` the reason first.

### R7 — Weaker escaping on a new output sink
User-controlled data reaches a *new* output path (PDF HTML, email body, CSV/XLSX, a headless-browser render, a webhook payload) with weaker escaping than the established sibling path for the same field.
- **Detect**: for each user field the new sink emits, find the *old* path that emits the same field and diff the escaping. Blade: `{!! !!}` vs `{{ }}`. Look for raw concatenation into HTML/SQL/shell/CSV-formula.
- **Confirm**: the field is user-writable (check the model `$fillable`/`$guarded` and the form), and the new sink is reachable by default (e.g. the brick is in the shipped default template).
- **Story**: at best a broken document; with a headless-browser driver (Chromium/Puppeteer), injected `<script>` runs server-side and can read `file://` or hit internal endpoints. A regression from existing safe behaviour is the tell.
- **Fix shape**: match the sibling path's escaping for plain fields; run intentionally-rich fields through an allowlist HTML purifier; tighten the risky renderer's flags (drop `--allow-file-access-from-files`, embed assets as data URIs).

### R8 — Missing authz that every sibling has
A new privileged page / action skips the `canAccess()` / policy / `abort_unless` check that every peer in the same namespace has.
- **Detect**: `grep -rn "canAccess\|authorize(\|abort_unless\|->can(" ` across the sibling directory (e.g. `Filament/Admin/Pages/`). The new file is the one with no hit.
- **Confirm**: the page/action mutates shared or global state (system-wide templates, other tenants' data, roles), and the only gate is coarse panel/role access — so a lower privileged role (e.g. a junior "assist" staff role) can reach it.
- **Story**: not a breach in the demo, but a year of a junior user being *able* to rewrite every customer's invoice layout is one bad afternoon from an incident — and it silently widens the blast radius of R4/R7.
- **Fix shape**: add the `canAccess()` override matching the tightest comparable sibling; re-check in the mutating action (`abort_unless(static::canAccess(), 403)`).

### R9 — Unwatched scheduled / queued / webhook entry point
Something added to `$schedule`, a queue, or a webhook handler that can throw, with no failure notification, retry cap, or dead-letter.
- **Detect**: diff hits in `Console/Kernel`/`routes/console.php`/`app/Console`, `ShouldQueue` classes, `$schedule->`, webhook routes. Check for `->onFailure()`, `->emailOutputOnFailure()`, `$tries`/`$backoff`, a `failed()` method, a DLQ.
- **Confirm**: on failure it either goes fully silent, or retries forever, or the schedule silently stops.
- **Story**: the one thing that *should* fill the inbox when it breaks (a nightly job) instead fails quietly for a year; or a poison message retries 8M times.
- **Fix shape**: failure notification to a real channel; bounded `$tries` + backoff; surface a "last succeeded at" somewhere observable.

### R10 — Unchecked external-dependency assumption
A new path assumes a binary / service / font / API is present, with no capability check and no graceful degradation — worse if the risky option is the *default*.
- **Detect**: new use of `Browsershot`/`puppeteer`/`wkhtmltopdf`/`Process::`/`exec`/an SDK client. Check `config/*` for the driver default (`env('X_DRIVER', 'safe-default')` vs `'risky-default'`).
- **Confirm**: on a stock deploy without that dependency, the path 500s rather than falling back.
- **Story**: works on the maintainer's box, 500s on every self-hoster who didn't install Node. A year of "PDF download is broken" tickets from a subset of installs.
- **Fix shape**: keep the pure/no-dep option as the default; probe for the dependency and degrade with a clear message; document the opt-in.

### R11 — Orphaned resource lifecycle
New per-tenant / per-entity storage (files, rows in an unmanaged store, cache keys, external objects) with no cleanup on parent deletion, and not covered by backups/migrations.
- **Detect**: new writes keyed by `company_id`/`user_id`/tenant to a Storage disk or external store. Check the parent model's observer (`deleted()`), cascade rules, and whether the store is in the DB-dump / backup path.
- **Confirm**: deleting the parent leaves the child data behind; the store isn't in any migration or backup routine.
- **Story**: not loud, but a year of accumulated orphans, and a restore-from-backup that's silently incomplete because this store was never in the dump.
- **Fix shape**: cleanup hook on parent delete; document/automate the store's backup; consider whether it should have been a DB table.

## Step 3 — Rank and write the findings doc

Severity for this lens = **unattended blast radius**:

- **BLOCKER** — fails on every request to a common path on a stock deploy (R1, R10 when default), OR fails silently with data loss (R3, R6), OR a security regression reachable by default (R7), OR privileged mutation open to the wrong role (R8 on global state).
- **HIGH** — systemic-outage amplifier (R4), failure with no user-visible error (R2), floods logs (R5), silent scheduled-job failure (R9).
- **MEDIUM** — degraded-but-visible failure, or a gap that only bites a subset of installs / an edge case (R11, R5 on a rare path, R10 when opt-in).
- **LOW** — cosmetic-under-failure, or needs an unlikely combination.

Write `~/projects/<project>/_notes/<slug>-release-resilience-YYYY-MM-DD.md`
(`<project>` = the top-level dir under `~/projects/`, e.g. `invoiceplane-2`;
`<slug>` = the feature, e.g. `report-builder`). Structure:

1. **Headline verdict** — "empty inbox?" yes/no + the blocker count, as a
   table: risk · severity · one-line · story id.
2. **Findings** — one per confirmed item: `Rn` id, severity, file:line, the
   confirmed failure path (traced, not from the diff), the fix shape.
3. **Cleared** — checks run that found nothing, one line each, so the reader
   knows the coverage.
4. **What was changed** (if the run also applied fixes — it usually should
   NOT; this lens reports).

Per the user's standing notes-file convention: the file is the deliverable;
the terminal reply is a short receipt (verdict + blocker count + file path +
one line per blocker).

## Step 4 — SMART stories + build prompt (separate files)

The user's established follow-up: each finding becomes a SMART user story,
then a single build prompt implements them. Keep narrative and actionable in
**separate files** (this matches the `handoff` skill's hard rule).

- `~/projects/<project>/_notes/<slug>-prod-readiness-stories-YYYY-MM-DD.md`
  — one story per finding: `As a <role>, I want <capability>, so that
  <benefit>` + **Specific** (what exactly, with file:line) + **Measurable**
  (acceptance criteria, each a testable assertion) + **Achievable** (scope /
  approach, XS–L) + **Relevant** (the unattended-year risk it removes) +
  **Time-bound** (rough size). End with a suggested PR grouping, marking
  which PRs are the release gate.
- `~/projects/<project>/_notes/<slug>-prod-readiness-build-prompt-YYYY-MM-DD.md`
  — one task per story, all independent, following the house build-prompt
  conventions: run everything via the project's real container/stack (never
  host tooling), the project's test framework and comment style, verified
  `file:line` refs (read the actual code first — never guess), per-task
  tests covering the allow **and** the failure/deny path, and a mandatory
  regression-gate + structured report-back section. State the current
  green baseline (test counts) so drift is visible.

## Notes

- Composes with `/review-panel` (breadth) — run that too; this is the
  narrow unattended-survival pass, and its findings are ranked on a
  different axis.
- This lens **reports**; it does not fix. The output is the three notes
  files, not a diff. Apply fixes only if the user explicitly asks.
- If the diff also touches CI: a `workflow_dispatch`-only test/lint workflow
  is itself an R-class finding — CI that never runs on push/PR is not a
  safety net (note it, and offer to flip it to `push`/`pull_request`).
- Don't invent findings to fill the checklist. "Cleared: R2, R6, R9 — no
  streamed responses, no swallowed catches, nothing scheduled" is a
  complete and useful answer.
