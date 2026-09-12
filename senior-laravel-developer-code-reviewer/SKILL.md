---
name: senior-laravel-developer-code-reviewer
description: "Orchestrates existing Laravel skills to produce structured PR reviews"
---

# Purpose

This skill does not implement rules — it orchestrates existing skills to produce a complete pull request review.

## Delegation model

This reviewer delegates evaluation to existing skills, when present in the project.

**Architecture** — delegate to:
- `application-architecture-standard`
- `service-layer`
- `dto-contract`
- `safe-refactoring-rules`
- `laravel-modules` (if the project uses modular architecture)
- any project-specific schema/PK convention skill (e.g. non-standard primary keys)

**Tests** — use, if present in the project:
- `filament-resource-testing` (if the project uses Filament)
- `data-layer-contracts`

These *add* to the test pass; they do not replace it. The built-in test smell checklist below is mandatory and runs on every review regardless of which delegate skills exist — it was added after a review missed a hardcoded `assertCount(10, ...)` brittleness bug specifically because "focus on weak tests" was too vague to catch it without a concrete pattern to check for.

**Security** — use:
- `security-review-checklist`
- otherwise infer from architecture + test gaps

## Built-in test smell checklist (mandatory, always runs)

Run all four checks below against every test file touched by the PR (or, for a full-suite audit, every test file in scope). Do not skip this because a delegate test skill exists or doesn't — it is the baseline, not a fallback.

### 1. Brittle exact-count / exact-list assertions

**Bad**: `assertCount(10, Country::values())`, `assertCount(8, AbsenceReason::values())`. Any assertion of an *exact* size or *exact* membership list over a collection that legitimately grows (countries, currencies, roles, permissions, enum cases, statuses, languages, ...). It breaks the instant someone makes an unrelated, correct addition — a maintenance tax disguised as coverage. A test method name that spells out or numbers the count (`it_returns_all_ten_x`, `it_has_exactly_five_y`) is a strong signal on its own.

**Good**: assert the known-stable members exist (`assertArrayHasKey('DK', $values)` per member, or a handful of representative ones), not the total count. If a count truly is invariant (e.g. a fixed enum of 3 payment types that will never grow), assert it but leave a comment saying why it's safe to hardcode.

Detection: `grep -rn "assertCount([0-9]" tests/` then check whether the collection being counted is a lookup table/enum whose members grow over time. Also grep test names for `_all_\|_exactly_\|_returns_all_`.

### 2. Untested FormRequest validation / authorization

For each FormRequest touched by the PR:
- Does any test send an invalid/missing required field and assert the 422/redirect+session-errors response? If `rules()` has no test ever triggering a failure, the validation logic is unverified.
- If `authorize()` has real logic (permission check, role check, ownership check — not a bare `return true`), does any test assert the *denied* path, not just the happy path?

A FormRequest with only happy-path coverage looks tested (the route has tests!) but its actual contract — what it rejects and who it blocks — has zero verification.

Detection: list the FormRequest classes touched, grep `tests/` for the class name and for the controller action that consumes it; check whether any hit asserts a failure/denial outcome (422, redirect with `assertSessionHasErrors`, 403).

### 3. Status-code-only assertions

**Bad**: a test whose entire Assert section is `->assertOk()`, `->assertStatus(200)`, or `->assertSuccessful()` with nothing else. This passes even if the endpoint silently no-ops, returns an empty page, or never touches the database — it only proves the app didn't crash.

**Good**: pair the status assertion with content (`assertSee`, `assertJson`, `assertViewHas`), database state (`assertDatabaseHas`/`Missing`, `assertSoftDeleted`), or header checks (downloads: content-type, content-disposition) — whatever the endpoint is actually supposed to produce.

Detection: for each test method, check whether `assertOk()|assertStatus(200)|assertSuccessful()` is the *only* assertion call in the method body.

### 4. Tautological / no-op assertions

**Bad**: `$this->assertTrue(true);` as the sole Assert — especially common after a Mockery `->expects($this->once())->method(...)` setup, where the mock's own expectation already did the real verification and the explicit Assert block checks nothing about the code's actual return value or side effects.

**Good**: assert on the value/response/state the Act step actually produced. If a mock expectation is the real check, that's fine — but still assert something about the method's return value too, so the test fails if the return value silently regresses even though the mock call happened correctly.

Detection: `grep -rn "assertTrue(true)" tests/` then check if it's the only assertion in the method, and whether the Act step's return value is ever captured or asserted on.

## Review process

**1. Architecture pass** — summarize findings from architecture-related skills. Do not restate rules; only report violations.

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
