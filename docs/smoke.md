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
