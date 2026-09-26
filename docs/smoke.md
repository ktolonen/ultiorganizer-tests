# Smoke Testing

## Purpose

The `smoke` suite is the smallest runtime page check in the harness.

It exists to answer one question quickly:

Does a known allowlist of important public pages render without obvious runtime failures?

## Configuration

Smoke coverage is declared per case in `config/matrix.json` under `smoke_pages`.

Each page entry defines:

- `id`
- `query`

The smoke test requests each page through `index.php` and fails if it sees:

- non-`200` HTTP status
- PHP fatal errors
- PHP parse errors
- PHP warnings or notices in the response
- PHP warnings or notices newly written to the Apache error log

## Page content tests

Besides the `smoke_pages` allowlist, `tests/Smoke` holds a few HTTP content
contracts for logic that lives in page files rather than `lib/`, so the
in-process suites cannot reach it:

- `GameplayPageContentTest`: cap events on the public gameplay replay.
- `ScoresheetHistoryPageTest`: the login-gated scoresheet history pages. It
  logs in as the fixture superadmin, seeds `uo_scoresheet_history` rows for
  game 700 directly, and deletes them again. It pins hiding unchanged re-saves,
  pairing saved and current points by order, and the season page's links.

These assert only locale-independent output (row counts, CSS classes, links),
since the `config-overrides` case renders pages in fi_FI.

## Characteristics

Smoke is intentionally:

- small
- deterministic
- public by default
- quick to run

This makes it suitable for day-to-day confidence checks and simple regression coverage.

## Artifacts

Smoke writes:

- suite log
- JUnit XML
- failure details in the summary

When a page fails, the summary includes:

- page id
- query
- status code
- response snippet
- Apache log excerpt

## When To Use

Use `smoke` when you want:

- a fast public runtime sanity check
- a stable regression signal
- a small suite suitable for repeated local runs

If you need broader discovery, authenticated coverage, or path security checks, use `crawl` instead.
