# Deep Coverage Readiness

## Purpose

Checkpoint 2 proved that shallow per-file coverage is practical for carefully chosen top-level `lib/*.php` files.

Deep per-file coverage is different. It requires explicit decisions about where the harness should grow and where the Ultiorganizer codebase should be refactored to make behavior testable without recreating whole page-entry bootstrap.

This document records the current requirements before broader cluster expansion.

## What The Pilot Confirmed

The harness side is good enough for early expansion when a file is mostly one of these:

- a direct helper file with stable pure behavior
- a DB-backed read helper with deterministic fixture-backed outputs
- a guard file with isolated include or direct-access behavior

The current harness already supports:

- deterministic one-file-per-lib mapping in `config/lib-test-catalog.json`
- targeted per-file execution with `./libtest:run --lib-file <name>`
- named `LegacyApp` load profiles for a few known stacks
- structured triage when a file is only partially testable under the current boundary

## What Blocks Deep Coverage

The main blockers are inside the SUT, not in the harness.

### 1. Entrypoint bootstrap hidden inside helper calls

Some lib functions look standalone but depend on state initialized elsewhere.

Current example:

- `lib/configuration.functions.php` exposes wrappers such as `GetPageTitle()`, `GetDefaultLocale()`, and `GetDefTimeZone()`
- `GetPageTitle()` calls `utf8entities()`
- `utf8entities()` lives in `localization.php`, not in `lib/`

That means some apparent lib-level helpers are really entrypoint-coupled.

### 2. Side-effect exits instead of returnable failures

Many mutation and authorization branches still use `die()`, `header()`, or `exit()`.

Examples:

- `lib/user.functions.php`
- `lib/auth.guard.php`
- `lib/view.guard.php`
- `lib/game.functions.php`
- `lib/team.functions.php`
- `lib/pool.functions.php`
- `lib/statistical.functions.php`

This makes deep coverage expensive because tests must trap process termination instead of asserting normal return values or exceptions.

### 3. Global request and session coupling

Several files read or mutate:

- `$_SESSION`
- `$_SERVER`
- `$_GET`
- `$_POST`

This is manageable for shallow tests but becomes fragile when many functions in the same file mix pure logic, DB access, and request/session state.

### 4. Wide include chains and cyclic dependency clusters

The top-level files are not independent modules.

Notable clusters:

- `team.functions.php` -> `pool.functions.php` -> `team.functions.php`
- `user.functions.php` -> `team.functions.php`, `reservation.functions.php`, `season.functions.php`, `series.functions.php`, `common.functions.php`
- `common.functions.php` -> `comment.functions.php` -> `spirit.functions.php` -> `user.functions.php`
- `statistical.functions.php` -> `season.functions.php`, `standings.functions.php`, `player.functions.php`, `series.functions.php`

Deep coverage becomes slow and ambiguous when “test one file” actually means “bootstrap half the legacy graph”.

## Requirements For Deep Coverage

Deep per-file coverage should not proceed cluster-by-cluster until these requirements are either satisfied or explicitly waived for that cluster.

### Requirement A: Stable file boundary

For the target file, we need to know which functions are genuinely owned by that file and which ones are wrappers over broader application bootstrap.

Practical rule:

- direct queries and direct transforms are fair per-file targets
- entrypoint wrappers should either move out of the file or be documented as partial-coverage only

### Requirement B: Testable failure behavior

For branches that currently terminate the request, deep coverage is much easier if the code can:

- return `false`
- return a result object or status array
- throw a domain exception

instead of:

- `die()`
- `exit()`
- inline redirect headers

The harness can still test termination behavior, but that should be the exception, not the default path for whole files.

### Requirement C: Explicit dependency seams

A deep testable file should not require hidden setup from unrelated entrypoints.

Preferred refactor direction in Ultiorganizer:

- move formatting helpers needed by lib functions into `lib/`
- stop relying on globals set by page entrypoints when a function can accept an argument instead
- isolate locale/session/request readers behind small helper functions

### Requirement D: Read path and write path separation

Deep coverage is easier when files separate:

- pure helpers
- DB-backed reads
- authorization checks
- mutating writes
- HTTP/redirect behavior

Current large files often mix all of these in one namespace. That is the biggest structural reason per-file deep coverage gets expensive.

## Recommended Refactors In Ultiorganizer

These refactors would materially improve deep coverage without requiring a large architecture rewrite.

### 1. Move bootstrap-only wrappers out of lib helper files

Example:

- if a helper depends on `localization.php`, either move that wrapper to entrypoint/bootstrap code or move the required formatter into a true lib dependency

### 2. Replace hard `die()`/`exit()` branches in lib mutations

Recommended pattern:

- low-level lib function returns a result or throws
- page/controller layer decides whether to redirect, render, or terminate

This single change would unlock much deeper tests in:

- `user.functions.php`
- `team.functions.php`
- `pool.functions.php`
- `game.functions.php`
- `season.functions.php`

### 3. Break cyclic include clusters

Priority cycles worth attacking first:

- `team.functions.php` <-> `pool.functions.php`
- `common.functions.php` -> `spirit.functions.php` -> `user.functions.php` -> `team.functions.php`

Even a partial split into smaller helper files would reduce loader ambiguity.

### 4. Extract rights checks from mutations

Preferred pattern:

- `canEditX(...)`
- `updateX(...)`

instead of functions that both decide authorization and immediately terminate on failure.

That makes both happy-path and denied-path tests much cheaper.

### 5. Extract query-only readers from mixed files

For large files, the cheapest deep-coverage win is often:

- keep read-only DB access in one file
- move writes and request/redirect behavior elsewhere

This preserves legacy behavior while making deterministic integration tests much easier.

## Suggested Next Expansion Strategy

Before attempting deep coverage for the heaviest files, prefer one more study-oriented expansion by cluster:

### Safer next candidates

- `country.functions.php`
- `season.functions.php` read paths only
- `standings.functions.php`
- `session.functions.php`

### Refactor-first candidates

- `user.functions.php`
- `game.functions.php`
- `pool.functions.php`
- `team.functions.php` write paths
- `statistical.functions.php` mutation paths

## Decision Rule

When adding another per-file test, ask:

1. Can this behavior be exercised with a small named load profile and deterministic fixture data?
2. If it fails, will the failure produce a useful assertion instead of process termination noise?
3. If not, is the right next move a harness feature or a small refactor in Ultiorganizer?

If the honest answer is “small refactor in Ultiorganizer”, do that first.
