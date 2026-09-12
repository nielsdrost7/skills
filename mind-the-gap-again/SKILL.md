---
name: mind-the-gap-again
description: Generate real frontend tests that prove the browser reaches every backend-proven required-field failure — driven by the same DB-schema ground truth mind-the-gap already exports, not fuzzy PHPUnit-title-to-E2E-title matching
---

# Skill: mind-the-gap-again

## The gap this exists for

A PHPUnit test proves "saving an invoice without a customer fails." Nothing
proves a real user, in a real browser, actually gets that same failure —
the E2E suite might only ever fill the customer field in on the happy path
and never once submit without it. [[mind-the-gap]] catches "the rule
doesn't exist anywhere." This catches "the rule exists and is proven
server-side, but nothing proves the browser path to it still works."

Anchor example, hand-verified in a Laravel + Filament reference project:
a PHPUnit test (`it_fails_to_create_invoice_without_required_customer`)
proved the server-side rule, while the corresponding Playwright spec only
ever filled the customer field in — never once submitting without it. A
whole-codebase check (v1, since superseded — see below) found this wasn't
isolated: 121 of 145 failure-path PHPUnit tests across all 8 modules had no
matching E2E coverage.

## Version history — read this before reusing the wrong approach

**v1 (abandoned): fuzzy title-matching.** Compare PHPUnit method names like
`it_fails_to_create_X_without_required_Y` against Playwright test titles by
keyword overlap. This found the scale of the problem but not a fix: a title
that matches still isn't a test that works — a title-matching report can't
prove anything, it only proves two strings share words. Do not resurrect
this as the primary mechanism; it's fine as a one-off discovery aid at most.

**v2 (built, working, current): schema-driven generation.** The backend
audit already looks at the DB schema to know which fields are required;
the frontend tests can do the exact same thing. Every required column is
already known — it's the exact same `schema.json` fact mind-the-gap's
backend audit exports (NOT NULL, no default). So: for every required column
of every resource, open the real create form, fill in a fully valid
submission for every OTHER required field, leave only the target field
blank, submit for real, and assert the browser genuinely rejects it. No
test-name matching anywhere — the backend fact and the frontend test target
the same column directly.

## Where this lives (a deliberate, corrected decision)

**Not** in `testrunner` as a standalone tool. These tests mirror specific
PHPUnit tests, so they belong where PHPUnit tests belong — alongside them,
in the target app's own module structure:

| File | Role |
|---|---|
| `Modules/Core/Tests/E2E/required-field-helpers.js` | Shared logic: opens a resource's create form (modal or dedicated page), classifies every rendered field, fills valid values, and asserts the two real rejection mechanisms below. Framework-specific (Filament v5) — see adaptation notes. |
| `Modules/<Name>/Tests/E2E/required-fields.spec.js` | One per module (Core, Clients, Invoices, Quotes, Payments, Products, Projects, Expenses — 8 modules in this app). Loads that module's slice of the schema export, generates one test per required column. |

A `testrunner`-based prototype (`generate-required-field-tests.js` +
`required-field-omission.spec.js`) proved the mechanism first — 58/58
passing there — before the module split. That prototype was deleted once
the real per-module version worked; don't rebuild it as a parallel
implementation. If you need this capability in a new codebase that doesn't
have a `testrunner`-style tool yet, prototyping there first (fast
iteration, no need to touch the target app while designing the mechanism)
is still a reasonable path — just move the working result into the app's
own test suite afterward, same as here.

## The two real rejection mechanisms (confirmed via live DOM/network inspection, not assumed)

Filament v5 renders required fields two structurally different ways, and
each fails a submission differently:

1. **Native-HTML-backed fields** (plain text/textarea/number/date, native
   `<select>` — `->required()` rendered as a real `required` attribute):
   the **browser** blocks the submission itself via native constraint
   validation. No request ever reaches the server. Assert
   `el.checkValidity() === false` and a non-empty `el.validationMessage` —
   the same mechanism an existing native-field E2E spec in this suite
   already established.
2. **Filament's custom JS-driven Select** (relationship fields — no native
   `<select>` at all, just a `<button role="combobox" id="form.X">`, no
   native `required` semantics to hook into): the request **does** reach
   the server, Livewire returns real validation errors, and Filament
   renders them as `<p data-validation-error class="fi-fo-field-wrp-error-message">`
   inside that field's `.fi-fo-field` wrapper. Assert that element is
   present with non-empty text.

Get this wrong and a test either can't fail (asserting a mechanism that
never fires) or produces a false pass (finding *some* error on the page
that isn't actually about the field under test). Both were real bugs hit
while building this — see "Debugging notes" below.

## The schema source

`loadSchemaForModule(moduleName)` in `required-field-helpers.js` runs
`php artisan mind-the-gap:export-schema` and filters to resources whose
`resourceClass` starts with `Modules\<Name>\`. It **always** shells out
through the app's own container —
`docker exec <workspace-container> sh -c "cd /var/www/... && php artisan ..."`
— never a bare host `php artisan`, not even as a first attempt. Bare-host
`php artisan` only reaches the database when the configured DB host happens
to resolve to `127.0.0.1`; on any other setup it silently can't connect.
Running through the container that already has the correct DB host
configured avoids that failure mode entirely, so don't "optimize" this into
a try-bare-host-then-fall-back-to-Docker path — that reintroduces the exact
debugging trap this avoids, for no benefit.

## Why storageState auth matters here (a real, measured fix, not a guess)

Early versions logged in fresh for every single generated test (matching
how the `testrunner` prototype had always done it). That produced
consistent, non-deterministic login-timeout flake — 1-2 different tests
per run failing on the shared login step under the load of ~20+ fresh
serial logins, always recovering on retry. Moving these tests into the
target app's own E2E suite fixed this **as a side effect**, not a
deliberate fix: that suite's `global-setup.js` logs in **once** for the
entire run and reuses `auth.json` storageState. Zero login-related flake
since, and ~750ms-1s per test instead of several seconds. If you ever build
a schema-driven generator like this as a standalone tool again (not
integrated into the target app's own suite), give it a shared-login
mechanism from the start — don't repeat the fresh-login-per-test mistake.

## Debugging notes (useful if this mechanism needs re-deriving after a DOM change)

- **Two "Create" buttons can match one accessible-name query.** Filament
  renders a small inline "create a related record" quick-add button next
  to a relationship Select (`title="Create"`) *and* the real form submit
  button, both matchable by `getByRole('button', {name: 'Create'})`. Target
  the real one via `button[type="submit"]`, not accessible-name alone.
- **Not every native-required field carries a `name` attribute.** Some
  (e.g. a native `<input type="date">`) bind only via
  `wire:model="data.X"`, with `id="form.X"` and no `name` at all. Matching
  only `[name^="data."]` silently skips these — they then sit unfilled and
  block the whole form's submission via their own native-required
  constraint, which reads as a mysterious, unrelated failure on a
  *different* field's test. Match `wire:model` first, `name` as fallback.
- **Playwright's built-in `visible` state check can hang on a genuinely
  visible, interactive element.** This app's modal wrapper can report a
  zero-height `getBoundingClientRect()` (confirmed: `display:block`,
  `opacity:1`, real content rendered on screen, but the wrapper's own box
  computes to zero height) while fully usable underneath — some CSS layout
  detail lets the visible content escape the wrapper's own box
  contribution. `dialog.waitFor({state:'visible'})` then times out
  indefinitely even though the modal is plainly open in a screenshot. Fix:
  poll Alpine's own state signal directly —
  `page.waitForFunction(el => el.classList.contains('fi-modal-open'), ...)`
  — instead of Playwright's stricter geometry-based visibility check.
- **A Livewire-rendered error can exist on the page without matching a
  scoped Playwright locator query.** `scope.locator('.fi-fo-field').filter({has: ...})`
  returned zero matches even though the error element was directly visible
  in a screenshot. Walking from the field's control to its `.fi-fo-field`
  ancestor via one `element.evaluate()` call (real DOM `.closest()`) proved
  more reliable than chaining Playwright locator filters across a `scope`
  that can be either a modal dialog or the full page body.

## Find gaps now, not afterwards

Both this skill and [[mind-the-gap]] exist because the same bug class kept
getting found **reactively** — while debugging something unrelated — instead
of by a deliberate, upfront pass. A CI-workflow precondition gap (see
[[mind-the-gap]]'s "A second gap class" section) was found this same
reactive way once too, which is what prompted turning this into a standing
rule rather than a one-off cleanup: find these gaps proactively, at the
start of the task, not after a failure forces the issue.

Concretely: when starting any task that touches a Filament resource, a form,
a CI workflow, or anything else these two skills' mechanisms cover, run the
relevant audit **before** the task's main work, not only when a failure
forces the issue. Both skills already build *permanent* CI-gated checks for
exactly this reason (see each skill's own "don't build a one-off report"
anti-pattern) — the fastest way to "find gaps now" in an already-audited
codebase is simply to run those existing permanent tests up front
(`ClassCoverageInventoryTest`-style: cheap, already there, no excuse to skip
them and find the same gap the slow way again).

## Adapting to a different codebase (e.g. InvoicePlane v1.8.0)

- **The schema source** (`requiredColumns()`, filtering by NOT NULL/no
  default) is stack-agnostic — same shape as [[mind-the-gap]]'s backend
  export, adapt the same way (CodeIgniter: `$this->db->field_data()` etc.,
  see that skill).
- **The field classification and fill/assert logic is entirely
  Filament-v5-specific** (`.fi-fo-field`, `button[role="combobox"]`,
  `.fi-fo-field-wrp-error-message`, the `wire:model`/`name` inconsistency).
  None of it carries over to a different framework's rendered markup.
  Inspect the real live DOM for the target app before writing equivalent
  classification logic — do not assume CodeIgniter's plain HTML forms
  share any of these patterns. They likely render required fields as
  ordinary `<input required>` with CI's own error-message markup instead,
  which would actually make the native-constraint-validation half of this
  *simpler* (no custom-JS-Select case to handle at all) — verify rather
  than assume either direction.
- **Where the generated tests belong** carries over directly: alongside
  whatever the target app's PHPUnit-equivalent tests live, not in a
  separate tool.
