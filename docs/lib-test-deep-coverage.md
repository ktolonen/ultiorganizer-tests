# Deep Coverage Limits

Some SUT code cannot be covered in-process no matter how good the tests are. The limits come from the SUT's structure, not the harness. When a lib file stalls below its coverage target, the cause is usually one of these.

## Structural Blockers

1. **Termination instead of return.** Guards and mutations that end in `die()`, `exit()`, or `header()` redirects cannot have their terminal branch asserted: `exit()` kills PHPUnit, and `runInSeparateProcess` records the child's `exit()` as an error. Test the boolean predicate the guard branches on instead, and note the gap in `triage_notes`.
2. **Entrypoint coupling.** Some lib functions call helpers defined outside `lib/`. For example, `GetPageTitle()` calls `utf8entities()` from `localization.php`. Those need shims or wider loading, and the outside code never shows in coverage.
3. **Request and session globals.** Functions that read or write `$_SESSION`, `$_SERVER`, `$_GET`, or `$_POST` need explicit setup and teardown. Check which key a function actually reads.
4. **Include cycles.** Files are not independent modules. For example, `team` ↔ `pool`, and `common` → `comment` → `spirit` → `user` → `team`. Loading one file loads a cluster, so shims and load order interact (see [Pitfalls](lib-test-pitfalls.md)).

## Decision Rule

Before forcing coverage, ask:

1. Can the behavior be exercised with a named load profile and deterministic fixture data?
2. Will a failure produce a useful assertion rather than process-termination noise?

If not, record the ceiling in `triage_notes` and move on. The fix is an SUT refactor, such as returning results instead of terminating, separating rights checks from mutations, or moving helpers into `lib/`. That is a change to propose upstream, not something to do inside a test task.
