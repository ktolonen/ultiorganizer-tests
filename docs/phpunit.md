# PHPUnit Suites

Five suites run through PHPUnit (`phpunit.xml.dist`) inside the prepared runtime: copied SUT, generated config, fresh fixture DB.

| Suite | Directory | Runs | Use for |
|---|---|---|---|
| `unit` | `tests/Unit/` | In-process | PHP behavior with no DB dependence |
| `integration` | `tests/Integration/` | In-process | Behavior that depends on schema or fixtures |
| `export` | `tests/Export/` | HTTP | `ext/` output contracts ([Export](export.md)) |
| `api` | `tests/Api/` | HTTP | `/api/v1` contracts ([API](api.md)) |
| `smoke` | `tests/Smoke/` | HTTP | Page-level behavior ([Smoke](smoke.md)) |

Per-file lib tests live under `tests/{Unit,Integration}/Lib/`; see [Lib Tests](lib-tests.md). Use `crawl` rather than PHPUnit for broad route discovery.

Each suite writes a raw log and JUnit XML; the summary records test and failure counts and the first failed test. Filter with `./test:<suite> --test-filter <pattern>`.

An empty JUnit file (`JUnit XML file was empty`), with the log's progress dots stopping mid-run and no PHPUnit summary, means a test ran an `exit()` or `die()` in-process. A bare `exit()` returns 0, so the suite looks clean while every later test and every earlier failure is lost. Estimate the kill point from the last percentage shown, then run the neighbouring classes alone with `--test-filter <Class>`: the offender stops after a few dots on its own too. Test the predicate instead of the terminating wrapper (see [Pitfalls](lib-test-pitfalls.md) §9). Guards that compare `realpath()` results are a common trap: under `vendor/bin/phpunit`, `$_SERVER['SCRIPT_FILENAME']` is relative, so its `realpath()` is `false`, the same as for any missing file.

## Code Coverage

Only the in-process `unit` and `integration` suites produce coverage; HTTP suites run the SUT under Apache and yield none.

- PCOV is installed in `php-test` but disabled; the harness enables it only for those two suites.
- Scope is the SUT `lib/` tree minus vendored directories (`<source>` in `phpunit.xml.dist`). Code outside `lib/` (page entrypoints, `localization.php`) never appears.
- Each suite writes `coverage/<suite>.cov`; `phpcov` then merges them into `coverage/html/index.html`, `coverage/clover.xml` (absolute container paths), `coverage/coverage.txt` (overall summary), and `coverage/coverage.json` (percent used by `report:html`).
- `coverage/` is wiped at the start of every run, so rerun the suite rather than trust an old directory. Cases without an in-process suite produce none.
- For one lib file, use `./libtest:coverage --lib-file <file>`.

Overall line coverage stays low by design: lib tests aim for depth in the file under test, not a rising global percentage.
