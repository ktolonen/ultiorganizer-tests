---
name: write-crawl-plan
description: Add or update harness crawl coverage in `config/matrix.json`. Use when the required validation is broader HTTP discovery, authenticated page coverage, direct endpoint fetching, or anonymous security path probing. Prefer extending an existing case over creating a new one unless the environment itself changes.
metadata:
  short-description: Write or update crawl plans
---

# Write Crawl Plan

Write or update crawl-plan configuration for this harness.

Always read these references first:

- `docs/crawl.md`
- `docs/matrix.md`
- `docs/runtime.md`
- `docs/reporting.md`

## Purpose

Use this skill when the right solution is to add or change `crawl_plans` in `config/matrix.json`.

Typical uses:

- broader public route coverage
- authenticated admin-visible route coverage
- direct fetches for public endpoint files
- anonymous security-oriented path checks

Do not use this skill for small deterministic public page checks that fit `smoke_pages`.

## Plan Type Selection

Current crawl plan types:

- `follow_links`: recursive in-scope page crawling, optionally authenticated
- `php_files`: direct fetches of scoped runtime `.php` endpoints
- `path_probes`: fixed-path requests with status and body-safety assertions

Choose the smallest fitting type:

- use `follow_links` for navigable page coverage
- use `php_files` for direct endpoint enumeration under one HTTP-addressable directory
- use `path_probes` for security-sensitive blocked-path expectations

## Matrix Rules

- Prefer adding the plan to an existing case when only coverage changes.
- Add a new case only when customization, config profile, fixture pack, or environment meaning changes.
- Keep crawl settings declarative in `config/matrix.json`.
- Do not move crawl orchestration into ad hoc scripts.

## Follow Links Rules

- Start from one stable entry page.
- Keep `max_depth`, `max_pages`, and `max_pages_per_view` explicit.
- Use auth only when the target area genuinely requires it.
- Exclude obviously destructive or state-changing routes with `reject_regex`.
- Prefer one focused authenticated plan over one huge plan that mixes unrelated scopes.

## PHP Files Rules

- Scope `input_root` narrowly.
- Only target directories that are actually HTTP-addressable in the runtime webroot.
- Do not point this at `lib/`, config directories, or mixed internal source trees just because files end in `.php`.
- Use this for endpoint-style directories such as `ext/` where direct fetches are meaningful.

## Path Probe Rules

- Use path probes for anonymous or access-control-sensitive URLs.
- Assert expected blocked responses explicitly with `expected_statuses`.
- Add `forbidden_body_regexes` for obvious secret or source leakage markers.
- Keep probes small and intentional instead of turning them into a blind scan.

## Auth Rules

- Prefer deterministic fixture-backed credentials when auth is needed.
- Keep destructive DB admin or mutation routes excluded unless the task explicitly requires them and the behavior is safe to probe.
- Make the account scope obvious in the plan id and description through naming.

## Workflow

1. Decide whether the need is `smoke` or `crawl`. Prefer `smoke` if the check is small and deterministic.
2. Choose the existing case unless the environment itself must differ.
3. Choose the smallest crawl plan type that matches the goal.
4. Add explicit limits and exclusions.
5. Run `./test:crawl` or `./test:case <case-id> --suites crawl`.
6. Inspect the plan artifact directory if the run fails.

## Boundaries

- Do not create a new case just for extra URL coverage.
- Do not turn security-sensitive probing into an unrestricted authenticated crawl.
- Do not depend on manual runtime edits.
- Do not use crawl plans when a direct PHPUnit assertion would be clearer and smaller.
