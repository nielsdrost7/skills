---
name: senior-developer-code-reviewer
description: "Orchestrates existing project skills to produce structured PR reviews, for any PHP application (Laravel, CodeIgniter, Symfony, or a plain/legacy codebase like InvoicePlane)"
---

# Purpose

This skill does not implement rules — it orchestrates existing skills to produce a complete pull request review, and falls back to built-in framework-agnostic checks wherever no project-specific skill exists to delegate to.

This is the generalized sibling of `senior-laravel-developer-code-reviewer`. Use that one for a pure Laravel project where all its delegate skills (`service-layer`, `dto-contract`, `laravel-modules`, ...) apply directly. Use this one for anything else — CodeIgniter, Symfony, a framework mix, or a plain/legacy PHP codebase — where forcing Laravel-specific delegate skills onto the review would just report "not applicable" across the whole architecture pass instead of actually reviewing anything.

## Delegation model

This reviewer delegates evaluation to existing project skills, when present.

**Architecture** — delegate to whatever project-specific architecture/convention skills exist. Examples: `application-architecture-standard`, `service-layer`, `dto-contract`, `safe-refactoring-rules`, `laravel-modules` (Laravel apps); a project's own layering, module, or naming-convention skill (any framework). Any project-specific schema/PK convention skill applies regardless of framework (e.g. non-standard primary keys).

**If no architecture skill exists for this project**, don't skip the pass — run the built-in framework-agnostic architecture checklist below instead.

**Tests** — use, if present in the project: `filament-resource-testing` (Filament), `data-layer-contracts`, or an equivalent test-convention skill for the project's own framework. These *add* to the test pass; they do not replace it. The built-in test smell checklist below is mandatory and runs on every review regardless of which delegate skills exist — it was added after a review missed a hardcoded `assertCount(10, ...)` brittleness bug specifically because "focus on weak tests" was too vague to catch it without a concrete pattern to check for.

**Security** — use `security-review-checklist` if present, otherwise infer from architecture + test gaps.

## Built-in architecture smell checklist (runs when no project-specific architecture skill exists)

These are the framework-agnostic concerns every layered PHP application shares, regardless of whether it's Laravel, CodeIgniter, Symfony, or a plain MVC codebase. Run this instead of leaving the architecture pass empty.

### 1. Business logic living in the controller/action instead of a dedicated class

**Bad**: a controller method that does validation, branching business rules, and persistence all inline — the "fat controller" smell. It's untestable without spinning up the full HTTP stack and it silently duplicates once a second entry point (a CLI command, a queued job, a second controller) needs the same rule.

Detection: a controller method with more than ~30-40 lines, multiple `if`/`switch` branches on domain state (not just input shape), and direct model/DB calls interleaved with that branching. Compare against the project's own better-factored controllers — if most controllers in this codebase delegate to a service/library class and this one doesn't, that inconsistency is the finding, not an abstract rule.

### 2. Raw/string-built SQL instead of the framework's query layer

**Bad**: `$this->db->query("SELECT * FROM {$table} WHERE id = " . $id)` or equivalent string concatenation into a query, when the framework has a parameterized query builder or ORM available and used elsewhere in the codebase.

Detection: `grep -rn "->query(\"" application/ | grep -v "?"` (adjust the path to the project layout) — a query built via concatenation rather than bound parameters is both an injection risk and a sign this path isn't using the same abstraction as its siblings.

### 3. Duplicated validation or authorization logic

**Bad**: the same set of validation rules, or the same permission check, copy-pasted across multiple controllers/actions instead of shared through one mechanism (a validator class, a trait, a middleware/filter, a base controller method).

Detection: grep for a distinctive validation rule string or permission-check call across the directory that owns this kind of logic; if it appears verbatim in 3+ places with no shared definition, the fix is consolidation, and the review finding is that a future rule change will drift.

### 4. Inconsistent output escaping between similar output paths

**Bad**: two code paths that render the same kind of user-controlled field (an invoice note, a client name) with different escaping — one auto-escaped by the view layer, the other concatenated raw into HTML/PDF/CSV output.

Detection: for each user-writable field the diff outputs, find the established path that already renders the same field and diff the escaping. This mirrors `release-resilience`'s R7 check — if that skill is available, prefer running it for anything touching a new output sink; this built-in check exists for when it isn't.

## Built-in test smell checklist (mandatory, always runs)

Run all four checks below against every test file touched by the PR (or, for a full-suite audit, every test file in scope). Do not skip this because a delegate test skill exists or doesn't — it is the baseline, not a fallback. All four are written to detect the underlying pattern regardless of framework — adjust only the concrete method/class names to whatever the project's own test helpers use.

### 1. Brittle exact-count / exact-list assertions

**Bad**: `assertCount(10, Country::values())`, `assertCount(8, AbsenceReason::values())`. Any assertion of an *exact* size or *exact* membership list over a collection that legitimately grows (countries, currencies, roles, permissions, enum cases, statuses, languages, ...). It breaks the instant someone makes an unrelated, correct addition — a maintenance tax disguised as coverage. A test method name that spells out or numbers the count (`it_returns_all_ten_x`, `it_has_exactly_five_y`) is a strong signal on its own. Framework-agnostic — plain PHPUnit's `assertCount` works the same everywhere.

**Good**: assert the known-stable members exist (`assertArrayHasKey('DK', $values)` per member, or a handful of representative ones), not the total count. If a count truly is invariant (e.g. a fixed enum of 3 payment types that will never grow), assert it but leave a comment saying why it's safe to hardcode.

Detection: `grep -rn "assertCount([0-9]" tests/` then check whether the collection being counted is a lookup table/enum whose members grow over time. Also grep test names for `_all_\|_exactly_\|_returns_all_`.

### 2. Untested input validation / authorization

The mechanism varies by framework — check whichever this project actually uses:

- **Laravel**: a `FormRequest` class's `rules()` and `authorize()`.
- **CodeIgniter**: a controller's `$this->form_validation->set_rules(...)` calls, and any manual permission/ownership check before a mutating action.
- **Symfony**: a `Form` type's constraints, and a voter/security-attribute check.
- **Plain/legacy PHP**: whatever function or method centralizes input validation for that action, and whatever guard (session role check, ownership comparison) gates it.

For each validation/authorization point touched by the PR:
- Does any test send an invalid/missing required field and assert the failure response (422, redirect+session-errors, a re-rendered form with an error message — whatever this framework's real failure shape is)? If the rule has no test ever triggering a failure, the validation logic is unverified.
- If the authorization check has real logic (permission check, role check, ownership check — not a bare pass-through), does any test assert the *denied* path, not just the happy path?

A validation/authorization point with only happy-path coverage looks tested (the route has tests!) but its actual contract — what it rejects and who it blocks — has zero verification.

Detection: list the validation/authorization points touched, grep the test directory for the class/action name; check whether any hit asserts a failure/denial outcome. For CodeIgniter specifically: `grep -rn "set_rules(" application/*/controllers/` to find validation points, then grep tests for the same controller/method with an assertion on the failure path.

### 3. Status-code-only assertions

**Bad**: a test whose entire Assert section checks only the HTTP status code and nothing else — the exact method varies by framework/harness: Laravel's `->assertOk()` / `->assertStatus(200)` / `->assertSuccessful()`; a plain-PHPUnit harness's `assertEquals(200, $response->getStatusCode())` / `assertSame(404, $response->status)`; Symfony's `assertResponseStatusCodeSame()`. This passes even if the endpoint silently no-ops, returns an empty page, or never touches the database — it only proves the app didn't crash.

**Good**: pair the status assertion with content (`assertSee`, `assertJson`, `assertViewHas`, or the project's own body/fragment-matching helper), persisted state (`assertDatabaseHas`/`Missing`, `assertSoftDeleted`, or a direct row fetch), or header checks (downloads: content-type, content-disposition) — whatever the endpoint is actually supposed to produce. For a rejection/guard test specifically, also assert the rejected action left no trace (nothing written, nothing sent) — a guard that returns the right code while a mutation partially happened underneath it is a real bug this check alone won't catch.

Detection: for each test method, check whether a status-code assertion (whatever this project's method/shape for it is) is the *only* assertion call in the method body.

### 4. Tautological / no-op assertions

**Bad**: `$this->assertTrue(true);` as the sole Assert — especially common after a mock's `->expects($this->once())->method(...)` setup (or the project's own mocking convention), where the mock's own expectation already did the real verification and the explicit Assert block checks nothing about the code's actual return value or side effects.

**Good**: assert on the value/response/state the Act step actually produced. If a mock expectation is the real check, that's fine — but still assert something about the method's return value too, so the test fails if the return value silently regresses even though the mock call happened correctly.

Detection: `grep -rn "assertTrue(true)" tests/` then check if it's the only assertion in the method, and whether the Act step's return value is ever captured or asserted on.

## Review process

**1. Architecture pass** — summarize findings from architecture-related skills if present; otherwise run the built-in architecture smell checklist above. Do not restate rules; only report violations.

**2. Test pass** — run the built-in test smell checklist above (mandatory), then supplement with findings from any test-related delegate skills present in the project. Focus on: weak tests, missing coverage, nondeterministic tests, missing failure cases, and the four built-in smells above.

**3. Security pass** — identify: missing authorization, unsafe access paths, privilege escalation risks, missing validation.

**4. Consolidation** — merge findings into: critical issues (must fix), important issues, suggestions.

## Output format

- **Summary** — short PR assessment
- **Critical Issues** — bullets only
- **Important Issues** — bullets only
- **Suggestions** — bullets only
- **Test Risk Summary** — only risks, no rule explanation
- **Security Notes** — only vulnerabilities, no theory
- **Suggested Fixes** — copy/paste code only

## Constraints

- Do not restate rules defined in other skills.
- Do not include full explanations of SOLID, DRY, etc.
- Do not duplicate test philosophy definitions.
- Only report deviations.
- Keep output strictly diagnostic.
- Never force a framework-specific check onto a codebase that doesn't use that framework — mark it not applicable and use the built-in framework-agnostic equivalent instead.

## Prioritization

Report issues in this order:

1. Production bugs
2. Security issues
3. Data integrity risks
4. Data layer contract violations
5. Architecture violations
6. Maintainability improvements
7. Cosmetic suggestions

Never let style issues obscure correctness issues.

## Tone

Simple, direct, non-verbose. Explain issues like:

> "This bypasses the service layer and writes directly to the model."

Not:

> "This violates layered architecture principles..."
