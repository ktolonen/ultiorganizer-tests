# Per-File Lib Tests

Every top-level SUT `lib/*.php` file has exactly one matching PHPUnit file. The file-to-test mapping is tracked in `config/lib-test-catalog.json`, which is the source of truth.

## Catalog

The catalog has one entry per lib file:

| Field | Meaning |
|---|---|
| `test_suite`, `test_path`, `test_class` | The matching test |
| `strategy` | `direct_helper`, `fixture_backed`, `bootstrap_guard`, or `bootstrap_runtime` |
| `load_profile` | The `LegacyApp` load profile to start from |
| `status`, `test_path_exists` | Whether the test exists |
| `triage_status`, `triage_notes` | See [Triage](lib-test-triage.md) |

The top-level `targets` block sets the per-file coverage goal (`line_pct`, `function_pct`). An entry may override it with `line_target` or `function_target`. Read the catalog for current counts and targets; do not copy them into docs.

## Naming

- lib file `lib/<name>.php` → test `tests/<Unit|Integration>/Lib/<PascalName>LibTest.php`
- `common.functions.php` → `CommonFunctionsLibTest`
- `configuration.functions.php` → `ConfigurationFunctionsLibTest`

## Commands

| Command | Purpose |
|---|---|
| `./libtest:catalog-refresh` | Sync the catalog with the SUT's `lib/` (keeps triage fields) |
| `./libtest:missing` | List lib files without a test |
| `./libtest:scaffold --lib-file <f> [--force]` | Create the matching test file |
| `./libtest:run --lib-file <f>` | Run only the matching test |
| `./libtest:coverage --lib-file <f> [--suite-wide]` | Run it with coverage; JSON with uncovered and partial functions vs. targets. By default only the file's own test class runs; `--suite-wide` runs every unit and integration test, which is how a file also reached by other tests (for example `game.functions.php` through the scorekeeping API tests) is measured |
| `./libtest:triage-status [--lib-file <f> \| --all]` | Triage state for changed (default), one, or all files |

## Maintenance

- New lib file: refresh the catalog, scaffold the test, fill it in.
- Changed lib file: run its test, then triage any failure.

Before writing a test, read [Pitfalls](lib-test-pitfalls.md), and follow the assertion-quality rules in `AGENTS.md`.

## Timetable coverage

`TimetableFunctionsLibTest` checks public pool selection through follower chains,
root visibility, and division validity. Its rendering tests assert winner and draw
classes, live scores, forfeit versus spirit output, escaped flags and placeholder
names, live-media visibility, and balanced tables across schedule groups. Date
navigation pins the selected game IDs and the empty-event result.

The only remaining uncovered line in `timetable.functions.php` is the terminating
permission guard in `TimeTableSetMoveTimes`; running that refusal in-process would
terminate PHPUnit. Coverage targets and this limitation are recorded in the catalog.
