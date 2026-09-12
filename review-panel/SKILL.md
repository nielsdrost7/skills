---
name: review-panel
description: Free, local multi-agent code review — fans a PR's diff (or the current branch/a path) out across parallel correctness, security, simplification, test-coverage, and unattended-operation (release-resilience) lenses using ordinary subagents, then merges their findings into one deduplicated report. A self-hosted alternative to the billed cloud "ultra" review.
---

# Skill: review-panel

Runs a multi-angle code review by spawning several parallel `lens-reviewer` subagents against the same diff, each auditing from one distinct angle, then merges their findings into a single deduplicated, severity-ranked report via `ReportFindings`. Uses only regular subagent calls — no separate billed cloud job.

## Inputs

`$ARGUMENTS` (optional) — one of, in priority order:
- **a PR number** (`720` or `#720`) — the common case: review an open PR's diff
- empty: review the current branch against its merge-base with the default branch
- a branch name
- a file/directory path (scope the review to that path)

## Step 1 — Resolve the target and get the diff

- **PR number** (e.g. `#720`): the default when the argument looks like a number. If `gh` is available and the repo has a GitHub remote, use `gh pr diff <n>`. Print the PR title and head branch alongside the `--stat`.
- **Empty argument**: review the current branch. Base = `git merge-base <default-branch> HEAD`; refine with `git merge-base --fork-point <default-branch> HEAD` only when that returns a commit (it is reflog-dependent and comes back empty on a fresh clone). If neither resolves, fall back to `origin/HEAD` → `origin/main` → `origin/develop` as base; if the branch has no base at all, use the working-tree diff (`git diff HEAD`).
- **Branch name**: same as empty, but for the named branch vs its merge-base with the default branch.
- **Path**: scope the diff to that path (or, if the path has no pending changes, review the file contents).

Diffs are three-dot (`<base>...HEAD`) — only what the branch added, never changes that landed on the base since the fork (so merging the default branch in does not pollute the review scope).

Print the resolved target, the base commit as `<short-sha> <subject>`, and a one-line `--stat` summary before continuing. If the diff is empty, say so and stop — don't spawn agents for nothing.

## Step 1.5 — Record the test-gate state once (do not make this a lens job)

The panel reasons about *latent* defects in the diff. Whether the suite is green is a separate fact that belongs in the report header, established once here — not re-run by every lens (wasteful, and a lens without the repo's flake context will misread a known flake as a regression).

- **If the branch/PR has CI**: read the latest gate results (`gh pr checks <n>` or the branch's workflow runs) and record them verbatim — e.g. `pint ✓ · phpstan ✓ · phpunit 722·1 skip ✓ · e2e 123·123 ✓`. Don't run anything.
- **If there is no usable CI signal**: run once, here, the deterministic gates the repo owns but doesn't CI-wire — linter (`pint`), static analysis (`phpstan`), and the *targeted* suite for the touched modules (smallest `--filter` regex covering the changed files; this repo mandates a single `|`-joined `--filter`, never repeated flags — for Report Builder changes the CLAUDE.md-required filter is `AdminReportBuilderTest|CompanyReportBuilderTest|MasonDocumentConverterTest|MasonBricksTest`). Record each result on the gate line. Never run the full suite here.
- **A gate the repo has but never runs in CI is itself a finding** — note it (e.g. "phpstan exists but is `workflow_dispatch`-only") so it lands in the report as a weak-safety-net observation.
- **Scope limits**: duplication detection (phpcpd and similar) is **Lens 3's** job, not this step's. Complexity/naming metric tools (phpmd and similar) are **out of scope** for the panel entirely — too high-volume and low-severity for a severity-ranked report.
- **Note, don't chase.** If a recorded gate is red, state which check and stop treating green as a precondition — the review still proceeds. If a targeted run trips a documented flake (CLAUDE.md lists them), record it as a known flake, not a finding.

Carry this line into the final `ReportFindings` report header so the reader knows the baseline the review sits on top of.

## Step 2 — Fan out to lenses

Spawn all of the following in **one message** (multiple `Agent` tool calls in a single response, so they run in parallel), with `subagent_type: lens-reviewer`. Give every agent the same diff (paste it inline, or the exact command to reproduce it, plus the repo root path) and exactly ONE lens:

1. **Correctness & bugs** — logic errors, edge cases, off-by-one, null/undefined handling, race conditions, error-handling gaps.
2. **Security** — injection, authz/authn gaps, secrets, unsafe deserialization, SSRF, path traversal. If a `security-review-checklist` or `security-review` skill exists in this environment, tell the agent to use it.
3. **Simplification, reuse & efficiency** — dead code, unneeded abstraction, duplicated logic, obvious performance issues.
4. **Test coverage** — missing tests for new/changed behavior, weak assertions, tests that would still pass if the logic were wrong.
5. **Unattended operation (release resilience)** — what breaks with nobody watching for a year: deploy-provisioning gaps, failures past a point of no return, discarded failure signals, missing per-item isolation in fan-out, per-request throws on systemic misconfig, silent disappearances, unwatched scheduled/queued entry points, unchecked external-dependency assumptions, orphaned resource lifecycles. Tell this agent to apply the `release-resilience` skill's R1–R11 checklist to the diff and score severity as **unattended blast radius** (does month-4-you get paged, or is it cosmetic). Deliberate overlap with lenses 1 and 2 — Step 3's dedupe collapses it, and two lenses flagging the same gap raises confidence.

Add a 6th **architecture/consistency** lens only when the repo has relevant project-specific convention skills available (e.g. `application-architecture-standard`, `service-layer`, `dto-contract`, `data-layer-contracts`, `laravel-modules`) — tell that agent which ones to check the diff against.

Every lens agent's prompt must include the diff/target, its ONE assigned lens, and end with:

> Verify every finding before reporting it — reproduce it or trace the exact failure path in the actual (non-diff-truncated) file. Call `ReportFindings` exactly once with your verified findings, most severe first (empty array if nothing survives verification). Do not report anything you have not personally verified.

## Step 3 — Wait, then merge

Wait for all lens agents to finish (you'll get completion notifications — do not poll or read their transcripts directly). Once all are back:

- Pool every finding from every lens.
- Dedupe: findings at the same file+line, or clearly describing the same underlying issue from different angles, collapse into one — keep the strongest verdict and the clearer `failure_scenario`.
- Drop anything without a verified `CONFIRMED`/`PLAUSIBLE` verdict.
- Sort most-severe-first (correctness/security defects above style/simplification nits).
- Call `ReportFindings` yourself exactly once with the final merged list. This is the skill's actual output — don't also restate the findings as prose.

## Notes

- This mirrors what a multi-reviewer cloud pass does (several independent reviewers, parallel, structured findings) but runs entirely as ordinary subagents inside the current session, billed the same as any other subagent work — there is no separate paid job.
- For a quick/cheap single-pass review, use `/code-review` directly instead; reach for `/review-panel` when the extra parallel depth is worth the extra tokens.
- Lens 5 embeds the `release-resilience` methodology as one angle of a broader review. When the release-survival question is the *whole* point (a release gate, a go/no-go), run `release-resilience` on its own instead — it produces SMART stories and a build prompt, which this panel does not.
