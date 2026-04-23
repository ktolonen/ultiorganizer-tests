# Crawl Testing

## Purpose

The `crawl` suite is the broadest runtime check in the harness.

It is used for:

- route discovery
- authenticated page coverage
- direct-file probing
- anonymous security path checks
- artifact-heavy debugging when a page load triggers PHP/runtime errors

Unlike `smoke`, crawl coverage is not meant to be tiny. It is meant to surface wider runtime problems while still running against the harness-managed disposable environment.

## Configuration

Crawl coverage is declared per case in `config/matrix.json` under `crawl_plans`.

Each plan has:

- `id`
- `type`
- type-specific settings such as start path, auth, limits, or probe definitions

Current plan types:

- `follow_links`: log in optionally, fetch a start page, and follow in-scope links recursively
- `php_files`: fetch directly addressable `.php` endpoints under a scoped runtime directory
- `path_probes`: request fixed sensitive paths and assert they stay blocked or redirected

## Current Default Plans

The default case currently uses these plans:

- `public-follow-links`: anonymous recursive crawl from frontpage
- `public-ext-php`: direct fetches for `ext/*.php`
- `superadmin-follow-links`: authenticated recursive crawl from admin settings
- `anonymous-sensitive-paths`: anonymous checks for blocked direct-file and traversal-style requests

## Artifacts

Each crawl plan writes artifacts under:

- `reports/cases/<case-id>/<run-id>/crawl/<plan-id>/`

Typical artifacts include:

- raw crawler log
- manifest of fetched pages
- downloaded page files
- path probe log for fixed security probes

The case summary includes one result entry per crawl plan with:

- status
- duration
- log path
- artifact root
- plan-specific details such as downloaded page count or failed probes

## When To Use

Use `crawl` when you want:

- broader runtime coverage than `smoke`
- admin-visible route coverage
- anonymous access checks for sensitive paths
- saved artifacts for diagnosing PHP warnings, notices, or fatal errors

Do not use `crawl` as a replacement for small deterministic regression checks. Keep that role with `smoke`.
