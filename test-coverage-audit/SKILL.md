---
name: test-coverage-audit
description: Diff-scoped, gap-first test-coverage review — "what did this branch change, and would a regression in it ship undetected?". A cheap-to-expensive triage (test-file discovery → size survey → name-list-as-coverage-map → targeted reads for weak assertions → diff the code's behaviours against the test map) that scales to a large suite without reading every test body. Produces a severity-ranked gap doc with an "already covered — don't fix" section. Framework-agnostic (PHPUnit / Pest / Jest / pytest / Go / RSpec). Composes as Lens 4 of /review-panel.
---

# Skill: test-coverage-audit

A focused review lens. It does **not** ask "are these tests well written?" across
the whole suite — that's `sturdy-phpunit-review`. It asks:

> **This branch changed N things. For each one, is there a test that fails
> if that change regresses? And are the tests that exist real, or would they
> pass on a broken implementation?**

Gap-first: the output is mostly *behaviours in the diff with no guarding test*,
not *improve this existing test*. Run it in addition to a normal review.

The method is a cost ladder — each rung is cheaper than reading test bodies,
and most of the answer comes from the cheap rungs. Do not jump to full reads.

## Inputs

`$ARGUMENTS` (optional) — one of:
- **a PR number** (`720` / `#720`) — the common case
- empty: the current branch vs its merge-base with the default branch
- a branch name
- a path (scope the audit to that directory/file)

## Step 0 — Resolve the diff and know what the code does

Resolve the target to a diff (`gh pr diff <n>`, or `git diff <merge-base>...HEAD`,
three-dot). Then **read the changed non-test source** — every changed function,
and enough around it to see its branches, error paths, new config flags, new
early returns, new fallback chains. Step 5 is a comparison; you can't do it
without one side. If a prior review pass in this session already read this
source, reuse that — don't re-read.

Print the resolved target + `--stat`. Empty diff → say so, stop.

## Step 1 — Diff-scoped test-file discovery

```
git diff --name-only <base>...HEAD | grep -E '<test-path-pattern>' | grep -iE '<feature-keywords>'
```

Only the test files this branch touches, filtered to the subsystem under review.
Adjust both patterns to the project (see Notes for per-framework globs). Also
note *changed source files with no corresponding changed test file* — that list
is half the finding set already.

## Step 2 — Size / shape survey (cheap)

Per test file, count test cases and sort descending:

```
for f in <test files>; do
  echo "$(grep -cE '<test-decl-pattern>' "$f")  $f"
done | sort -rn
```

This is "how much attention did each area get" — a file with 2 cases next to a
600-line source change is a flag on its own. Correct for any double-count (e.g.
PHPUnit `#[Test]` + `public function it_` both match — halve it).

## Step 3 — Method-name enumeration as a coverage map

```
grep -E '<test-decl-pattern>' <the big files you don't want to fully read>
```

In a codebase with **intention-revealing test names**
(`it_cannot_load_another_companys_template`,
`it_rejects_logo_path_traversal_attempts`), the name list *is* a coverage map —
you learn what's asserted without opening a single body. Read the list against
the behaviours you catalogued in Step 0 and mark each behaviour
covered / not-covered / unclear.

If names are **not** descriptive (`testFoo`, `test_it_works`): fall back to
grepping each file for the SUT class/function name, `@covers`, the imports, and
the left-hand side of assertions — slower, but still cheaper than reading prose.

## Step 4 — Targeted reads for assertion quality

Full-read the small / new / recently-changed test files, and any that Step 3
left "unclear". Names tell you *what* is tested; only the body tells you whether
the assertion is real. Flag these **weak-assertion patterns** — a test with one
of these can pass on a broken implementation:

- `assertNotEmpty` / `assertNotNull` / bare truthiness as the only check
- a magic-prefix check with no content assertion —
  `assertStringStartsWith('%PDF', …)`, `assertStringContainsString('<html', …)`
  (a blank/garbled output passes)
- "it didn't throw" as the whole test — `assertIsArray`, `assertIsString`,
  `expect(fn).not.toThrow()` with nothing on the value
- asserting a **mocked** return value — tautological; the mock *is* the answer
- `assertCount(n, …)` with no assertion on what's in the collection
- `assertDatabaseHas` / `toHaveBeenCalled` keyed only on an id / with no args
- snapshot tests that **auto-(re)generate a missing fixture** — a careless
  delete+regenerate launders a real behaviour change into a green run; confirm
  the fixture is committed and actually diffed when output changes
- happy-path only for a function with branches, a fallback chain, or early
  returns — each unexercised branch is a finding
- time / randomness / ordering not frozen — the test is lucky, not correct
- asserting on a log line or a notification instead of the state change it
  stands in for

## Step 5 — Diff the code's behaviours against the test map

For every changed function in Step 0, walk its paths and ask *"which test name
or body exercises this exact path?"* Pay special attention to what the diff
*added*:

- a new conditional branch / `match` arm / `elseif` in a chain
- a new error path, guard clause, or `throw`
- a new config flag / env toggle and **both** its states
- a new early return
- a new public method or entry point
- a widened input contract (now also accepts null / array / a new shape)
- a security-relevant default (`isRemoteEnabled(false)`, an authz check,
  an escaping call) — these need a test that *fails if the default flips*

Each path with no guarding test is a gap. A changed security default with no
guard is the highest-severity kind.

## Step 6 — Rank and write the findings doc

Severity = **likelihood a real regression in this behaviour ships undetected**
(not coverage percentage). A flipped security default with no guard outranks an
untested logging branch.

Write `~/projects/<project>/_notes/<slug>-test-coverage-YYYY-MM-DD.md`
(`<project>` = the top-level dir under `~/projects/`). Include:

1. **Verdict** — one paragraph: is the suite strong or thin, and the shape of what's missing.
2. **Gaps table** — id, severity, one-line each.
3. **Per-gap detail** — the untested behaviour, the file:line, and the exact test to add (name + arrange/act/assert sketch + which file it belongs in).
4. **"Already covered — do not fix"** — every non-obvious concern that *is*
   guarded, with the test name. This stops the next person (or the next agent)
   re-deriving or duplicating existing coverage. It is as valuable as the gap list.
5. **Recommendation** — which gaps to close now vs schedule; call out the one or two you would not ship without.

Terminal reply is a pointer: the file path, the verdict sentence, and the
one-line headline per gap. The doc is the deliverable.

## Notes

- **Don't write the tests** unless the user asks. This skill finds and
  documents; closing the gaps is a separate, explicit step.
- **Sibling skills.** `sturdy-phpunit-review` = whole-suite quality of what
  exists. This = diff-scoped gaps in what changed. `mind-the-gap` = a specific
  schema/DOM/precondition gap class with a permanent audit. Run whichever
  matches the ask; they don't overlap in output.
- **As Lens 4 of `/review-panel`**: the lens-reviewer gets the diff and "audit
  test coverage" — this is the method it should follow. Standalone, run it when
  a branch is nominally green and you want to know whether green means anything.
- **Per-framework patterns** for Steps 1–3:
  | framework | test-path glob | test-decl pattern |
  |---|---|---|
  | PHPUnit | `Tests?/.*\.php$` | `#\[Test\]\|function test` |
  | Pest | `tests?/.*\.php$` | `^\s*(it\|test)\(` |
  | Jest/Vitest | `\.(test\|spec)\.[jt]sx?$` | `^\s*(it\|test)\(` |
  | pytest | `test_.*\.py$\|_test\.py$` | `^\s*def test_` |
  | Go | `_test\.go$` | `^func Test` |
  | RSpec | `_spec\.rb$` | `^\s*(it\|specify)\s` |
- A **query-count / performance guard** test (e.g. "assert N queries stay
  constant under a large dataset") is worth noting in the "already covered"
  section — it often pre-empts an efficiency finding from another lens.
- If the suite genuinely has no gaps in the diff, say so plainly. "Coverage is
  strong, here are the three narrow gaps" is a valid and common outcome.
