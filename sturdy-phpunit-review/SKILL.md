---
name: sturdy-phpunit-review
description: Audit a PHP project's PHPUnit tests for deterministic, behavior-focused, business-outcome coverage. Use when asked whether PHPUnit tests are sturdy, what tests can be improved, or to review an entire test suite against explicit quality criteria. Inventory tests with PHPUnit's discovery output, inspect every test's setup/action/assertions, run relevant tests when possible, and produce evidence-backed improvement recommendations without changing code unless requested.
---

# Sturdy PHPUnit Review

Review the complete PHPUnit suite and answer: “Can any tests be improved?” Treat a test as evidence of a production rule, not as evidence that an implementation detail was invoked.

## Review workflow

1. Establish scope and constraints.

   - Read `AGENTS.md`, `composer.json`, `phpunit.xml`, bootstrap files, and the relevant test configuration.
   - Identify the project root, PHPUnit executable, suites, database setup, fixtures/factories, and any required environment services.
   - Do not modify tests, production code, databases, or configuration during the audit unless the user explicitly asks for fixes.

2. Build a complete inventory before judging quality.

   Prefer the configured executable and run discovery from the project root:

   ```sh
   vendor/bin/phpunit --list-tests
   vendor/bin/phpunit --list-tests --testdox
   ```

   If those commands are unavailable, use the project's documented equivalent. Also enumerate test source files with `rg --files` so tests that are excluded, skipped, or not discovered are visible. Record the suite, file, class, and test method/name for every discovered test; note skipped/incomplete tests separately.

3. Run the suite safely when feasible.

   Run the full suite once, then targeted tests for findings that need confirmation. Capture failures, errors, warnings, risky/incomplete tests, skips, runtime, and environmental blockers. A green run is only execution evidence; it does not establish sturdy coverage.

4. Inspect each test, not just its name.

   For every test, trace the arrange/action/assert path into the public production entry point. Read nearby production code, schema/migrations, factories/fixtures, policies, and integration boundaries as needed. Use file and line references in findings. Distinguish an observed defect from a coverage opportunity and from a criterion that is not applicable to that behavior.

5. Evaluate the criteria below.

   Give each criterion one of `pass`, `partial`, `fail`, or `N/A`, with a short reason. Do not infer a pass from a method name, code coverage percentage, HTTP 2xx, “does not throw,” a mock expectation, or a test that merely executes.

6. Produce a prioritized report.

   Start with the highest-risk gaps: tests that can pass while the business rule is broken, missing authorization/tenant boundaries, missing state or side-effect assertions, nondeterminism/isolation problems, and regressions without a reproducer. For each recommendation include:

   - test file and method (or the closest stable location);
   - the criterion(s) involved;
   - concrete evidence from setup, action, and assertions;
   - the business behavior that is unproved;
   - a specific improvement, including the missing scenario/assertion and a suitable public entry point;
   - confidence and any verification limitation.

   Group related findings so one underlying weakness is not reported repeatedly. Include strengths and explicitly say when no improvement is justified. Never invent domain rules; label assumptions and request clarification only when the behavior cannot be established from the repository.

## Sturdy-test criteria

Assess all 22 criteria; preserve their intent and do not collapse them into a generic “good test” score.

1. Deterministic: same code/state yields the same result, independent of time, randomness, order, external services, or leftovers.
2. Observable behavior: asserts externally visible behavior, not private methods or incidental dependency calls.
3. Business outcome: proves a rule or workflow, not merely successful execution.
4. State transitions: verifies relevant before/after records, statuses, relationships, totals, and meaningful timestamps.
5. Side effects: verifies relevant jobs, events, notifications, emails, files, API calls, and audit records.
6. Meaningful assertions: rejects `assertTrue(true)`, status-only `assertOk()`, “does not throw,” construction-only, and route-exists-only tests.
7. Failure paths: covers invalid IDs, missing records, malformed input, rejected transitions, duplicates, dependency failures, and not-found cases where relevant.
8. Validation: identifies the specific rejected fields and rules, not merely that some validation error occurred.
9. Authentication/authorization: covers guests, unauthorized users, insufficient permissions, cross-account access, and tenant boundaries where relevant.
10. Invariants: protects rules that must remain true across implementation changes.
11. Realistic data flows: aligns factories/fixtures, constraints, services, schema, and database behavior with production, especially MySQL behavior.
12. Appropriate doubles: prefers fakes, fixtures, and real objects; mocks only genuine external boundaries and does not make expectations the main proof.
13. Refactoring resistance: remains valid if services, DTOs, repositories, or internal method names change without behavior changing.
14. One reason to fail: expresses one behavior/rule; multiple assertions may collectively prove that outcome.
15. Isolation/order independence: does not depend on other tests or leak shared state.
16. Minimal relevant setup: arranges only data needed for the scenario; incidental object graphs are a smell.
17. Non-brittle assertions: avoids complete HTML, incidental wording, generated IDs, framework internals, snapshots, and irrelevant ordering.
18. Real public entry point: exercises the controller, command, job, action, or interface production actually invokes for important behavior.
19. Intention-revealing name: states the rule or outcome, such as `it_prevents_users_from_deleting_the_last_active_tax_rate`.
20. Actionable failure: name and assertions identify the broken behavior immediately.
21. Appropriate feedback speed: uses unit tests for isolated rules but retains necessary feature/integration coverage.
22. Explicit regressions: fixed defects have a test reproducing the original failure.

## Reporting format

Use a compact summary followed by findings:

```text
Scope: <suites/files/tests>; execution: <command/result>; limitations: <none or details>

Overall: <short answer to whether tests can be improved>

Priority findings
1. [High] <behavioral gap> — <file:line / test name>
   Criteria: <numbers/names>
   Evidence: <what the test actually proves>
   Risk: <how the production bug could pass>
   Improve: <specific scenario/assertions/public entry point>

Coverage matrix
| Test | 1 | 2 | ... | 22 | Main recommendation |
| ...  | P | P | ... | F  | ... |

Strengths and non-applicable criteria
<brief evidence-backed notes>
```

For large suites, use a per-test table only for non-passing or noteworthy tests and provide aggregate counts for the rest, while retaining the complete inventory in the report or an attached/generated artifact. Use `P`, `Part`, `F`, and `N/A` only when the mapping is unambiguous.

## Review boundaries

- Do not equate line/branch coverage with test sturdiness; use coverage only as supporting evidence.
- Do not demand side-effect assertions where the behavior has no relevant side effect.
- Do not call a test weak solely because it is a unit test; judge whether the tested boundary is appropriate.
- Do not recommend broad rewrites when a focused assertion or missing scenario closes the gap.
- Separate test-quality findings from production defects discovered while understanding the behavior.
