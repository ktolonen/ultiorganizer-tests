# AGENTS.md

Dockerized test harness for the Ultiorganizer codebase (the SUT, default `../ultiorganizer`; override with `--sut-path`). Details live in `docs/` — start at `docs/README.md`.

## How it works

- `scripts/harness.py` (host CLI behind every wrapper) drives `scripts/container_runner.py` inside `php-test` (PHP 8.3 + Apache + PCOV + Node) alongside `mariadb`.
- Each run copies the read-only SUT mount to `.runtime/cases/<case-id>/sut`, injects test config (`ALLOW_INSTALL=true`), recreates the case DB, loads the SUT schema then `fixtures/<pack>.sql`, runs suites, and writes `reports/cases/<case-id>/<run-id>/`.
- Suites: `lint`, `unit`, `integration`, `export`, `api`, `smoke`, `crawl`. Only `unit`/`integration` run in-process and yield coverage (`lib/` tree only). `tests/Js` is separate host-Node tests (`./test:js`).
- `config/matrix.json` has 8 cases: `baseline-default` (all suites), six `customization-*` (smoke + crawl), `config-overrides` (integration + smoke, renders `fi_FI`).
- `config/lib-test-catalog.json` maps every top-level `lib/*.php` to one test file and holds coverage targets and triage notes.
- `mcp/server.py` is a thin MCP wrapper; never put orchestration logic in it.

## Entrypoints

- `./doctor`
- `./test:quick` (lint + unit + integration; the default)
- `./test:lint`, `./test:unit`, `./test:integration`, `./test:export`, `./test:api`, `./test:smoke`, `./test:crawl` (PHPUnit suites accept `--test-filter`)
- `./test:case <case-id> [--suites a,b]`, `./test:matrix`
- `./test:filter <case-id> <pattern>` (integration suite only)
- `./test:js`
- `./report:latest`, `./report:case <case-id>`, `./report:html`, `./report:clean`, `./logs:case <case-id>`
- `./libtest:catalog-refresh`, `./libtest:missing`, `./libtest:scaffold`, `./libtest:run`, `./libtest:coverage`, `./libtest:triage-status` (take `--lib-file <file>`)

Run `./test:matrix` before pushing: config-dependent assertions (locale, overridden settings) pass on `baseline-default` and fail on `config-overrides`.

## Test assertion quality

Reaching a line is not testing it. A test that runs a function without pinning its result passes even when the SUT is wrong.

- Prefer value assertions (`assertSame`/`assertEquals`/`assertCount`) against known fixture values. A type-only check (`assertIsArray`, `assertNotNull`, `assertTrue(true)`) as a method's only assertion is a smell; upgrade it when the fixture makes the value knowable.
- DB reads return strings: use `assertEquals(1, $v)` or `assertSame('1', $v)`. `assertContains` is strict (see `docs/lib-test-pitfalls.md`).
- Guard negative assertions (`false`/`null`/`[]`/`0`) against false passes: add a positive precondition (the row exists with the expected flags) **and** an in-test contrast driving the same function to the opposite result.
- Read the SUT function before asserting; it may read a different request key than expected (e.g. `GetTeamPlayers()` reads `$_GET['search']`, not `$_GET['team']`) and silently exercise the empty path.
- `exit()`/`die()`/`header()` terminal branches cannot be asserted in-process (`runInSeparateProcess` records the child `exit()` as an error). Test the predicate the guard branches on (`CanAccessSeason()`, `IsSeasonPublicExternal()`), not the `Enforce*`/`Require*` wrapper.
- Watch for tests that stay green on a broken line: only the non-triggering path tested with a comment explaining why, a weak assertion, `markTestSkipped`, or a test that works around the bug (comments like "to avoid", "SUT quirk"). To prove an assertion can fail, run it against a pre-change or mutated SUT worktree (see `docs/ai/write-phpunit-test/SKILL.md`).
- Use coverage to find unasserted branches in the target file: `./libtest:coverage --lib-file <file>` (see `docs/ai/use-coverage-for-tests/SKILL.md`). `coverage/` is wiped every run, so rerun rather than trust an old one.

## Working rules

- Keep the SUT read-only; test-only config belongs in the runtime copy.
- Add environments as cases in `config/matrix.json` and profiles in `config/profiles/`, not as script branches.
- Extend the Python orchestration rather than adding ad hoc shell logic.
- Never commit or hand-edit `.runtime/` or `reports/`.
- When behavior changes, update the relevant `docs/` topic; keep `README.md` a short entrypoint.
