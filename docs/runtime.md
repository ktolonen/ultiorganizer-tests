# Runtime

## Purpose

The harness prepares a disposable runtime copy of the SUT for each case run.

This is the main isolation boundary between:

- the production checkout
- test-only config and data

## Runtime Layout

Main runtime paths:

- `.runtime/cases/<case-id>/sut`: copied runtime SUT
- `.runtime/cases/<case-id>/maintenance-runtime`: writable maintenance directory for the runtime copy
- `.runtime/webroot`: symlink to the active runtime SUT
- `.runtime/phpunit-cache`: PHPUnit cache directory

## Why The Runtime Copy Exists

The production checkout is treated as read-only from the harness perspective.

The runtime copy allows the harness to:

- generate `conf/config.inc.php`
- point Apache at a prepared case-specific webroot
- keep test-only files and writable paths out of the production checkout

## Database Relationship

The runtime copy and disposable database are prepared together for each case run.

The runtime config points at:

- local MariaDB in Docker Compose
- the case-specific disposable database name

## Web Serving Model

Apache in `php-test` serves:

- `/workspace/.runtime/webroot`

That symlink points to the prepared runtime SUT for the active case.

HTTP-based suites such as `smoke` and `crawl` run against this served runtime, not against the original SUT mount.

## Design Rules

- Do not hand-edit `.runtime/`.
- Do not treat runtime outputs as source-controlled assets.
- Put test-only config in the runtime copy, not in the production repo.
- Recreate runtime state from harness code and config, not from manual edits.
