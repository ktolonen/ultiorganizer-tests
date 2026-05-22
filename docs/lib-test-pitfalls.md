# Lib Test Pitfalls

Concrete gotchas discovered while writing per-file lib tests for this harness.
These are specific to the PHP process reuse model, the fixture pack, and the SUT's
include graph. When a test fails in a surprising way, check here first.

## 1. Shim type hints must accept mixed input

When a lib function passes a DB column value to a shimmed helper (such as `utf8entities()`),
that value can be `null` even when the column is nominally a string. Using a strict `string`
type hint on the shim causes a `TypeError` that surfaces in a completely different test
and is hard to trace.

**Wrong:**
```php
function utf8entities(string $s): string
{
    return htmlentities($s, ENT_QUOTES, 'UTF-8');
}
```

**Right:**
```php
function utf8entities(mixed $s): string
{
    return htmlentities((string) $s, ENT_QUOTES, 'UTF-8');
}
```

Same rule applies to any shim whose argument flows from a DB row or user input.

## 2. Shims persist alphabetically across the entire suite run

PHPUnit reuses a single PHP process across all test files in a suite. A function defined
in an early file (`CommentFunctionsLibTest.php`) is still registered when a later file
(`SearchFunctionsLibTest.php`) runs. The `function_exists()` guard stops the second
registration silently, but the *first* definition's type signature is the one that applies
to all subsequent calls.

**Consequence:** a shim with a wrong type hint defined in file A can cause `TypeError`
in file B, with no obvious connection in the failure output.

**Rule:** treat every top-level shim as shared state for the whole integration suite.
The shim must be permissive enough for all callers that appear later alphabetically.

## 3. Never shim functions owned by the lib file under test

A shim only works for functions that live outside `lib/` — typically `localization.php` or
`translation.functions.php`. If you add a shim for a function that the target lib file also
defines, PHP raises "Cannot redeclare function" and the test process dies before any
assertion runs.

**Rule:** shim only non-lib helpers. For lib-owned dependencies, load the real file using
`LegacyApp::loadLibFilesUsingProfile()` or accept that the dependency chain must be loaded.

## 4. Transitive includes add real functions — and constants

Loading `game.functions.php` also loads `configuration.functions.php`, which defines
`ShowDefenseStats()`, `GetDefaultLocale()`, and friends. This is usually the right outcome —
you get the real implementation rather than a shim — but it means:

- You cannot shim those functions after loading the file that defines them.
- The transitive constants and functions are available in all tests in the same process,
  even for tests that did not explicitly load that file.

**Rule:** when a load unexpectedly makes a function available, check the include chain
in the SUT source before adding a redundant shim or an extra `loadLibFilesUsingProfile` call.

## 5. Aggregate SQL queries always return a row

A query of the form `SELECT COUNT(*) ... WHERE id = 999` returns one row even when no
matching record exists. Several SUT helpers (such as `ReservationInfo()`) use bare
aggregate selects that always return a row with nullable columns.

**Wrong:**
```php
$this->assertFalse((bool) ReservationInfo(99999));
```

**Right:**
```php
$info = ReservationInfo(99999);
$this->assertNull($info['id']);
```

**Rule:** when testing a function against a non-existent ID, check a specific nullable
field from the result rather than assuming the return value is falsy.

## 6. Functions that call exit() or die() cannot be tested inline

Any lib function that terminates the process — `DBRenderMaintenanceResponse()`,
authorization guards, redirect-and-die patterns — cannot be covered by a normal PHPUnit
test without process isolation. Calling them directly will abort the test runner.

**Rule:** do not write tests that invoke these branches. Mark them as a coverage gap in
triage notes (see `docs/lib-test-triage.md`) rather than attempting brittle output-buffering
hacks. `docs/lib-test-deep-coverage.md` covers why these branches are a structural blocker
and what the right long-term fix is.

## 7. Functions that echo must be wrapped in output buffering

Some lib functions (such as `UnscheduledTeams()`) echo diagnostic output mid-execution
rather than returning it. Without buffering, that output contaminates PHPUnit's test
report and may cause spurious failures.

```php
ob_start();
$result = SomeFunctionThatEchoes($arg);
ob_end_clean();
$this->assertIsArray($result);
```

**Rule:** wrap any call that may echo with `ob_start() / ob_end_clean()` rather than
asserting on the captured output unless the test specifically targets that output.

## 8. Prevent getSessionLocale() from calling GetDefaultLocale()

`getSessionLocale()` falls back to `GetDefaultLocale()` when the session locale is not
set. `GetDefaultLocale()` is defined in `configuration.functions.php`, which is not always
loaded. Without it, the test process dies with "undefined function".

**Fix:** set the locale in `setUp` before loading any lib file that calls `getSessionLocale()`:

```php
$_SESSION['userproperties']['locale'] = 'en_US';
```

This short-circuits the fallback and makes the load profile irrelevant for locale resolution.

## 9. Missing schema columns in the SUT

Some SUT functions contain queries that reference columns which no longer exist (or never
existed in the baseline fixture schema). `PlayerResults()`, for example, selects `email`
from `uo_player`, but that column lives in `uo_player_profile`. The test will produce a
fatal `mysqli_sql_exception` that looks like a harness problem but is actually a SUT bug.

**Rule:** if a test path triggers an SQL column-not-found error, do not try to work around it
by adding the column to the fixture. Record it as a triage note and test only the paths
that do not reach the broken query. The affected branch is unreachable until the SUT is fixed.
