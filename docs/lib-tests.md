# Per-File Lib Tests

## Purpose

This harness now tracks top-level `../ultiorganizer/lib/*.php` files through a deterministic one-file-per-lib model.

Checkpoint 1 establishes traceability and incremental tooling first. It does not require broad assertion depth for every lib file yet.

## Catalog

The machine-readable catalog lives at `config/lib-test-catalog.json`.

Each entry records:

- the SUT lib file under `lib/`
- the matching PHPUnit suite and target path
- a first-pass strategy classification
- the `LegacyApp` load profile to start from
- whether the matching PHPUnit file exists yet
- optional triage status and notes

Refresh the catalog from the current sibling SUT checkout with:

```sh
./libtest:catalog-refresh
```

## Naming Convention

For a top-level lib file:

- subject id: lib filename without `.php`
- test class: derived PascalCase name plus `LibTest`
- test path: `tests/<Unit|Integration>/Lib/<DerivedClassName>LibTest.php`

Examples:

- `lib/common.functions.php` -> `tests/Unit/Lib/CommonFunctionsLibTest.php`
- `lib/configuration.functions.php` -> `tests/Integration/Lib/ConfigurationFunctionsLibTest.php`
- `lib/include_only.guard.php` -> `tests/Unit/Lib/IncludeOnlyGuardLibTest.php`

The suite is a first-pass classification stored in the catalog and can be refined in later checkpoints.

## Incremental Commands

Show missing matching PHPUnit files:

```sh
./libtest:missing
```

Run one matching PHPUnit file through the normal case setup:

```sh
./libtest:run --lib-file common.functions.php
```

Scaffold one matching PHPUnit file:

```sh
./libtest:scaffold --lib-file common.functions.php
```

Show triage status for changed top-level lib files in the current SUT checkout:

```sh
./libtest:triage-status
```

Show triage status for one file or all catalog entries:

```sh
./libtest:triage-status --lib-file configuration.functions.php
./libtest:triage-status --all
```

## Checkpoint Workflow

Checkpoint 1 expectations:

- every top-level `lib/*.php` file appears in the catalog
- every catalog entry maps to exactly one deterministic target PHPUnit file path
- adding a new lib file means refreshing the catalog and scaffolding one new matching test file
- changing an existing lib file means rerunning the matching test and checking triage status

Checkpoint 2 and later should add assertions cluster-by-cluster instead of trying to deepen every file at once.

## Current Pilot

The rollout has now moved beyond the initial pilot. The current covered set includes:

- `common.functions.php`: pure helper coverage in `tests/Unit/Lib/CommonFunctionsLibTest.php`
- `configuration.functions.php`: DB-backed config reads in `tests/Integration/Lib/ConfigurationFunctionsLibTest.php`
- `include_only.guard.php`: direct-access guard behavior in `tests/Unit/Lib/IncludeOnlyGuardLibTest.php`
- `team.functions.php`: mixed legacy-heavy reads in `tests/Integration/Lib/TeamFunctionsLibTest.php`
- `country.functions.php`: fixture-backed country and team reads in `tests/Integration/Lib/CountryFunctionsLibTest.php`
- `season.functions.php`: stable season, pool, and reservation reads in `tests/Integration/Lib/SeasonFunctionsLibTest.php`
- `session.functions.php`: request/session helper behavior in `tests/Integration/Lib/SessionFunctionsLibTest.php`
- `standings.functions.php`: shallow ranking-helper coverage in `tests/Integration/Lib/StandingsFunctionsLibTest.php`
- `debug.functions.php`: debug output gating in `tests/Unit/Lib/DebugFunctionsLibTest.php`
- `HSVClass.php`: color conversion and object behavior in `tests/Unit/Lib/HSVClassLibTest.php`
- `location.functions.php`: deterministic location reads in `tests/Integration/Lib/LocationFunctionsLibTest.php`
- `url.functions.php`: non-media URL fixture reads in `tests/Integration/Lib/UrlFunctionsLibTest.php`
- `translation.functions.php`: pure `translate()` behavior in `tests/Unit/Lib/TranslationFunctionsLibTest.php`
- `plugin.functions.php`: plugin metadata parsing and filtering in `tests/Unit/Lib/PluginFunctionsLibTest.php`
- `logging.functions.php`: event category enumeration and direct event-log insert behavior in `tests/Integration/Lib/LoggingFunctionsLibTest.php`

These files validate the structure and load-profile assumptions while keeping the assertions narrow and trustworthy.

As of the latest catalog refresh:

- catalog entries: `40`
- covered files: `15`
- missing files: `25`

Refresh the catalog again before relying on those counts if the sibling SUT checkout has changed.

## Feasible Frontier

Under the current constraint set:

- no SUT refactors
- no broad bootstrap recreation
- no brittle redirect, `die()`, or mutation-heavy assertions

the remaining feasible work is narrower than the earlier checkpoints.

The most plausible next targets are:

- `yui.functions.php`: shallow output-shape assertions around `yuiLoad()`
- `search.functions.php`: only small HTML-form assertions, if dependency loading stays stable
- `sms.functions.php`: a narrow DB-backed insert/read test if another write-path file is still useful

The following files remain lower-priority or not currently worth forcing:

- `user.functions.php`
- `game.functions.php`
- `pool.functions.php` deeper paths
- `statistical.functions.php` deeper paths
- `spirit.functions.php`
- `seasonpoints.functions.php`
- `swissdraw.functions.php`
- `timetable.functions.php`
- `database.php`
- `database.maintenance.php`
- `api.functions.php`

## Transitional Rule

Legacy grouped tests can continue to exist while the per-file model is introduced. The catalog is the source of truth for new work and for future migration away from grouped coverage.
