---
name: write-crawl-plan
description: Add or update harness crawl coverage in `config/matrix.json`. Use when the required validation is broader HTTP discovery, authenticated page coverage, direct endpoint fetching, or anonymous security path probing. Prefer extending an existing case over creating a new one unless the environment itself changes.
metadata:
  short-description: Write or update crawl plans
---

# Write Crawl Plan

Add or update `crawl_plans` in `config/matrix.json`. Read `docs/crawl.md` and `docs/matrix.md` first.

Use a crawl plan for broad route discovery, authenticated route coverage, direct endpoint fetches, or anonymous security probes. A small deterministic page check belongs in `smoke_pages` or a smoke test instead.

## Choosing A Type

- `follow_links`: navigable pages. Start from one stable page, keep `max_depth`, `max_pages`, and `max_pages_per_view` explicit, and exclude destructive or state-changing routes with `reject_regex`. Use auth only when the area requires it, and prefer one focused authenticated plan over a sprawling one.
- `php_files`: direct fetches under one HTTP-addressable `input_root`, such as `ext/`. Never point it at `lib/` or config directories.
- `path_probes`: security expectations. Set `expected_statuses` and add `forbidden_body_regexes` for secret or source leakage. Keep the list small and intentional.

## Rules

- Extend an existing case; add a case only when the environment changes.
- Use the fixture credentials, and make the account scope obvious in the plan id.
- Keep configuration declarative. Do not add ad hoc scripts or manual runtime edits.

## Validation

Run `./test:crawl` or `./test:case <case-id> --suites crawl`. On failure, inspect `crawl/<plan-id>/` in the run directory.
