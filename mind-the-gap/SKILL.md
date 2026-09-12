---
name: mind-the-gap
description: Build a permanent, whole-codebase audit that catches "form field doesn't match its DB column constraint" bugs before a user does — both the backend form schema and the actually-rendered frontend DOM, cross-checked against real database introspection, not static source parsing. Also covers the general "N places must share a required precondition, verify they actually do" gap class (e.g. CI workflow steps) — see "A second gap class" section below.
---

# Skill: mind-the-gap

## The bug class this exists for

A DB column is `NOT NULL` / `UNIQUE` / length-limited, but the form field
that writes to it has no matching required/unique/max-length rule. Client
validation passes, the write reaches the DB, and it blows up as an
unhandled SQL 500 — often invisibly, because a modal-based create form
frequently just looks "stuck" rather than showing an error. These bugs are
individually cheap to fix and expensive to find one at a time, because they
only surface when a real user happens to type the wrong thing. This skill
was built after the same bug shape turned up repeatedly, one instance at a
time, in a Laravel + Filament codebase — the reference implementation below
comes from that project.

**Rule: no static parsing of form field definitions.** Closures, conditional
`->required(fn ($context) => ...)` rules, and shared form classes reused by
multiple resources all make source-text parsing miss real gaps or invent
fake ones. Always bind the *live, framework-evaluated* form/schema object
and read its resolved state, and always read the *actually rendered* DOM,
not the template source. This is the one non-negotiable design constraint —
every adaptation below exists to preserve it on a different stack.

## Two layers, one shared source of truth

1. **Backend audit** — introspect the live server-side form schema (bound
   to a real request context so conditional rules evaluate correctly) and
   cross-check every field against the real DB column it writes to (via the
   framework's own schema-introspection API, not `information_schema` SQL
   you maintain by hand). Runs as a permanent test in the app's own suite.
2. **Frontend audit** — crawl the real rendered pages with Playwright,
   read the actual DOM attributes (`required`, `maxlength`) on real form
   inputs, and cross-check those against the *same* DB column data. This
   catches drift the backend audit structurally cannot see: a Blade/view
   override, an attribute a disabled-at-render-time quirk strips, a field
   that silently isn't rendered at all.

Both layers read from **one exported JSON schema** (columns + unique
indexes + a shared "deliberate exception" allowlist), produced by the
backend framework's own introspection. Never let the two layers maintain
separate copies of "what's a deliberate gap" — that list is reviewed by
both audits' diffs, so duplicating it is how it silently drifts.

## Reference implementation (Laravel + Filament — copy this first)

Built and running in a modular Laravel + Filament application. Treat these
as the template to adapt, not just read:

| File | Role |
|---|---|
| `Modules/Core/Support/FormDbGapKnownExceptions.php` | The one shared `KNOWN_GAPS` const — `"ResourceClass:field" => "reason"`. Both audits read this, nothing else defines exceptions. |
| `Modules/Core/Tests/Feature/FormDbConstraintAuditTest.php` | Backend audit. Walks every resource in every Filament panel (`Filament::getPanel($id)->getResources()`), boots each resource's index page as a real Livewire component (`Livewire::actingAs($user)->test($indexPage->getPage(), $params)`), binds `Filament\Schemas\Schema::make($component->instance())->model($model)->operation('create')` — the `operation('create')` is required or conditional `->required(fn ($context) => ...)` rules silently evaluate wrong — then recurses `getChildComponents()` to collect every named field, and checks each against `Illuminate\Support\Facades\Schema::getColumns($table)` / `::getIndexes($table)` (Laravel 11+ native introspection, no Doctrine DBAL needed for reading). |
| `Modules/Core/Commands/ExportFormDbSchemaCommand.php` | `php artisan mind-the-gap:export-schema` — pure DB/Filament-metadata dump (no HTTP/Livewire context needed) of every resource's table columns + unique indexes + the shared `KNOWN_GAPS`, as JSON. This is the join key the frontend audit consumes. |
| `testrunner/find-form-gaps.js` | Frontend audit logic. Given an authenticated Playwright `page` and the exported schema JSON: navigates to each resource's index page, opens its create-form modal, reads every `input[name^="data."], textarea, select` inside it, strips the `data.` prefix Livewire renders field names with to recover the column name, and compares `required`/`aria-required`/`maxlength` DOM attributes against the same column data the backend audit used. |
| `testrunner/form-db-gaps.spec.js` | The permanent Playwright test that runs it, one `test()` per resource for isolated failure reporting. Deliberately lives at testrunner's root, not inside `tests-playwright/` — that directory is blanket-gitignored (disposable scan/generation output), and this spec is a permanent, committed regression gate. `make find-form-gaps` calls it by explicit path, so it doesn't need to be under Playwright's configured `testDir` to run. |
| `testrunner/Makefile` (`export-schema`, `find-form-gaps` targets) | `make find-form-gaps` = export schema fresh, then run the audit. |

Known-exceptions pattern (do not skip silently — this is what keeps the
allowlist itself honest):

```php
public const array KNOWN_GAPS = [
    'App\Filament\Resources\FooResource:bar_field' => 'reason a human can read in a diff',
];
```

## Workflow for a new codebase (e.g. InvoicePlane v1.8.0)

1. **Backend first.** Get *some* form of the backend audit running and
   green before touching the frontend layer — it will find real bugs
   immediately (the reference project's first run found 9 in one pass; a
   follow-up found 4 more). Fix what it finds; do not loosen the check to
   make it pass.
2. **Export the schema JSON**, same shape as `ExportFormDbSchemaCommand`
   produces (table → columns with nullable/default/type/length → unique
   indexes → shared exceptions). This is the contract between the two
   layers — keep it stack-agnostic in shape even if the producer changes.
3. **Point a Playwright crawl at the real rendered app.** `testrunner/`
   (sibling directory to this project, its own git repo) already has
   route-discovery, authenticated crawling, and spec-generation
   infrastructure built for exactly this — reuse it rather than building a
   new crawler. `find-form-gaps.js`'s DOM-reading logic barely needs to
   change per stack; what changes is the field-name-to-column mapping
   (Filament/Livewire prefixes with `data.`; a plain HTML form usually
   won't) and the create-form-opening heuristic (Filament: click "New X" /
   "Add X", wait for `role=dialog`; a non-SPA app more likely has a
   dedicated `/create` page with no modal at all — **check a real rendered
   page before assuming**, don't port the modal-click logic blind).
4. Wire both as permanent CI gates, not one-off scripts — the entire point
   is catching future drift, not a single cleanup pass.

### Adapting the backend layer off Filament/Laravel (CodeIgniter, e.g. IP v1.8.0)

- **DB introspection**: no Laravel `Schema` facade. CodeIgniter's DB driver
  gives you `$this->db->field_data($table)` (name, type, `max_length`,
  `nullable`... check the exact keys for your CI version) and
  `$this->db->query("SHOW INDEX FROM {$table}")` (raw SQL, but this is
  reading structure, not app logic — acceptable here, unlike parsing form
  source).
- **Form rules**: CodeIgniter validation is usually
  `$this->form_validation->set_rules(...)` calls in a controller method,
  not a declarative schema object you can statically enumerate safely.
  Prefer **calling the real controller action in a test and inspecting
  `CI_Form_validation`'s registered rule set afterward via reflection**
  over grepping the PHP source — the same "live, evaluated state, not
  static text" principle the Laravel version follows. If the ruleset truly
  is static text with no conditionals in this codebase, static parsing is
  a defensible fallback, but confirm that assumption first rather than
  reaching for it by default.
- Everything downstream (comparing rules to columns, the shared
  `KNOWN_GAPS` allowlist, the JSON export shape) carries over unchanged in
  spirit.

### Adapting route/page discovery off `php artisan route:list`

CodeIgniter has no route-list export. Use `testrunner`'s `make shallow`
(follows links from the dashboard rather than needing `routes.json`)
instead of `make export-routes` / `make auto`.

## A second gap class this same discipline catches: CI/config precondition drift

The core insight isn't specific to DB columns — it's "a required precondition
is satisfied at *some* of the places that need it, and nothing checks that
it's satisfied at *all* of them." A GitHub Actions workflow is just another
place that discipline applies: sibling workflow files that should share a
setup step are exactly as prone to drift as sibling forms are.

Example, from a Laravel monorepo's CI: `phpunit.yml` ran `php artisan test`
with **no** Node/yarn/build step at all, while its sibling `quickstart.yml`
built frontend assets first. A Feature test doing a real HTTP request into a
Blade view that calls `@vite(...)` failed with "Unable to locate file in
Vite manifest" as a direct result — a missing precondition, not a real test
bug. This was found **reactively**, while chasing an unrelated test-failure
report, exactly the failure mode this skill exists to prevent. The fix
followed a "resolve one, resolve all" standard, and was two-fold:

1. Fix the one instance found (add the missing `yarn build` step).
2. Scan **every** sibling workflow for the same pattern before calling it
   done — not just the one that happened to surface first. In this case nine
   other workflows were checked by hand; all were either already correct,
   or didn't need the precondition (verified per-job, not assumed — see
   below), except the one fixed.
3. Turn the check itself into a permanent, whole-codebase, CI-gated audit,
   not a one-off pass — same anti-pattern this skill's own "Anti-patterns"
   section below already warns against for the DB/form case.

Reference implementation of that permanent audit:
`Modules/Core/Tests/Unit/CiWorkflowAssetBuildAuditTest.php` — parses every
`.github/workflows/*.yml` (via `Symfony\Component\Yaml\Yaml`, already a
transitive Composer dependency in most Laravel apps — check
`composer.lock`, not just `composer.json`, before assuming a new dependency
is needed), and for every job running `php artisan test` /
`vendor/bin/phpunit`, asserts an earlier step in the same job runs
`yarn build` / `npm run build`, unless the job is listed in a
`KNOWN_EXEMPT_JOBS` const with a reason — the exact same shared-allowlist
discipline as `FormDbGapKnownExceptions::KNOWN_GAPS`. A second test
(`every_known_exempt_job_still_exists`) keeps that allowlist itself honest
the same way the DB/form layer's own audits do. **Verify a "no build needed"
exemption is actually true before granting it** — don't assume a job is
exempt just because it looks similar to another; in this app two jobs
(`smoke.yml`, and `composer-update.yml`'s smoke step) are genuinely exempt
because their tests are proven — by reading what they run, not by
assumption — to only exercise `Livewire::test()` component mounts, which
never render the outer `@vite`-using layout via a real HTTP request.

**When to run this check**: at the *start* of any task that touches CI
workflow files, adds a new required build/setup step to one workflow, or
generally works with "several files that are supposed to agree" — not only
when a test failure forces the issue. That's the whole point: this class of
gap is cheap to find by deliberately comparing siblings, and expensive to
find one broken CI run at a time.

**The same principle extends further than CI workflows** — any place a
codebase has multiple files/configs that are supposed to stay in lockstep
(Docker Compose service definitions across environments, duplicated
per-package linter configs, per-module service-provider boilerplate) is a
candidate for this same "read every sibling, diff what's actually there,
don't assume the first one you check is representative" audit. Adapt the
mechanism (a small script/test that parses and compares the real files),
not just the one worked example above.

## Anti-patterns to avoid when extending this

- Adding an entry to the known-exceptions list to make a failing audit
  pass, instead of fixing the form or the migration. The list is for
  genuine, reasoned exceptions (e.g. a lookup-by-existing-value field where
  uniqueness would be actively wrong) — it needs a comment a stranger could
  evaluate, and check for it in review.
- Checking exact source text instead of live/rendered state (see "no
  static parsing" above) — this is the mistake that makes an audit like
  this either miss real bugs or cry wolf on conditional logic.
- Building the frontend crawler as a one-shot report script instead of a
  CI-gated permanent test. A report that nobody re-runs decays back into
  exactly the "find bugs one at a time, after the fact" problem this skill
  exists to solve.
