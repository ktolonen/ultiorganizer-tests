# Local Workflow

Point the harness at a checkout, run a suite or case, then inspect reports.

## Commands

| Command | Purpose |
|---|---|
| `./doctor` | Check SUT path, Docker, Compose, and DB connectivity |
| `./test:quick` | `lint` + `unit` + `integration` on `baseline-default`; the day-to-day command |
| `./test:{lint,unit,integration,export,api,smoke,crawl}` | One suite on `baseline-default`; PHPUnit suites accept `--test-filter` |
| `./test:case <case-id> [--suites a,b]` | One full case |
| `./test:matrix` | All cases; run before pushing |
| `./test:filter <case-id> <pattern>` | `integration` suite only, with a PHPUnit `--filter` |
| `./test:js` | Host Node tests for SUT JavaScript |
| `./report:latest`, `./report:case <case-id>` | Latest summary |
| `./logs:case <case-id>` | Log paths, including the Apache/PHP error log |
| `./report:html`, `./report:clean` | Browser index; prune old runs |
| `./libtest:*` | Per-file lib tests; see [Lib Tests](lib-tests.md) |

For a unit-suite filter use `./test:unit --test-filter <pattern>`; `./test:filter` never runs `unit`.

## Alternate Checkouts And PR Context

Every run command accepts `--sut-path` (default `../ultiorganizer`). The harness records the SUT's branch, commit, and dirty state, and keeps separate latest pointers per context label (inferred from the branch, or set with `--context-label`).

```sh
./test:case baseline-default \
  --sut-path ../ultiorganizer-pr-123 \
  --pr-number 123 --pr-head-ref feature/my-change --pr-base-ref main
./report:case baseline-default --context-label pr-123
```
