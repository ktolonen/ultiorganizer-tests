# Lib Test Pitfalls

Gotchas specific to this harness's process-reuse model, fixture pack, and the SUT's include graph. Check these first when a lib test fails in a surprising way.

## Shims

1. **Type shims permissively.** PHPUnit runs a whole suite in one PHP process. The first file to define a shim, such as `utf8entities()`, fixes its signature for every later file, and the `function_exists()` guard hides the redefinition. A strict `string` hint then raises `TypeError` in an unrelated later test when a DB column is `null`. Use `mixed` and cast inside the shim.
2. **Never shim a function owned by a lib file.** The shim collides with the real definition ("Cannot redeclare"), which kills the process. Shim only helpers outside `lib/`, such as those in `localization.php`. Load lib dependencies with `LegacyApp::loadLibFilesUsingProfile()`.
3. **Includes are transitive.** For example, loading `game.functions.php` also loads `configuration.functions.php`. After that you cannot shim those functions, and they stay defined for the rest of the process. Check the SUT include chain before adding a shim or another load.
4. **Set the session locale in `setUp()`.** Without `$_SESSION['userproperties']['locale'] = 'en_US';`, `getSessionLocale()` falls back to `GetDefaultLocale()`, which may not be loaded.

## Caches

5. **Request cache.** `SeasonInfo()` and similar helpers wrap reads in `CacheRemember()`, which keeps results in `$GLOBALS['runtime_cache']`. After a DB `UPDATE`, call `CacheForgetNamespace('<ns>')`, for example `season_info`. Call it again in the `finally` block that restores the row.
6. **Persistent query cache.** `DBQueryToValue`, `DBQueryToArray`, `DBQueryToRow`, and `DBQueryRowCount` also cache on disk, keyed by query string. After a write, flush all four namespaces (`db_query_value`, `db_query_array`, `db_query_row`, `db_query_rowcount`) before re-reading. Do not rely on toggling `PersistentCacheEnabled`. Many existing tests show the flush loop.

## Assertions

7. **Aggregate queries always return a row.** `SELECT COUNT(*) ... WHERE id = 99999` returns one row of `NULL`s, so a missing id is not falsy. Assert a specific field, such as `assertNull(ReservationInfo(99999)['id'])`.
8. **`assertContains` is strict.** DB reads return strings, so `assertContains(300, $ids)` fails against `'300'`. Assert `'300'` or map the array through `intval` first.
9. **Termination cannot be asserted in-process.** Calling a branch that ends in `exit()`, `die()`, or a redirect kills the runner. Test the decision predicate instead, for example `CanAccessSeason()` rather than an `Enforce*` wrapper. Record the gap in `triage_notes` (see [Deep Coverage Limits](lib-test-deep-coverage.md)).
10. **Buffer functions that echo.** Some helpers, such as `UnscheduledTeams()`, echo while running. Wrap the call in `ob_start()` / `ob_end_clean()`, then assert on the return value (`assertSame`, `assertCount`), not just its type.
11. **SQL errors may be SUT bugs.** A column-not-found `mysqli_sql_exception` usually means the SUT query is wrong, not the fixture. Do not add columns to fixtures to paper over it. Report the bug, and test only the paths that avoid it.

## Session

12. **Cover the "target is the current user" branch.** Role functions (`AddUserRole`, `RemoveUserRole`, `AddEditSeason`, `AddSeasonUserRole`, `AddPoolSelector`, and their removers) call `SetUserSessionData()` when `$userid == $_SESSION['uid']`. To cover it, call them for `'admin'`, the `setUp()` uid, and check `$_SESSION['userproperties']`. Clean up in `finally`.
