# Ultiorganizer Test Harness

Dockerized test harness for the [Ultiorganizer](https://github.com/ktolonen/ultiorganizer) codebase. By default it tests the sibling checkout `../ultiorganizer`; pass `--sut-path` for another checkout or worktree.

The SUT is never modified. Each run copies it into `.runtime/`, injects test-only config, recreates a disposable MariaDB database with a deterministic fixture, runs the requested suites, and writes results to `reports/`.

## Requirements

- Docker with Compose and access to the daemon
- Node on the host for `./test:js`
- The SUT checkout at `../ultiorganizer` (or `--sut-path`)

## Quick start

```sh
./doctor                         # check environment
./test:quick                     # lint + unit + integration (day-to-day)
./test:case baseline-default     # full default case
./test:matrix                    # every case (run before pushing)
./report:latest                  # latest summary
./report:html                    # browsable reports/index.html
```

Single suites: `./test:lint`, `./test:unit`, `./test:integration`, `./test:export`, `./test:api`, `./test:smoke`, `./test:crawl`, `./test:js`. Per-file lib tests: `./libtest:*`. See [Local Workflow](docs/local-workflow.md) for the full command list.

## Suites and cases

| Suite | Checks |
|---|---|
| `lint` | `php -l` over the SUT |
| `unit`, `integration` | In-process PHPUnit (with coverage) |
| `export`, `api` | HTTP contracts for `ext/` and `/api/v1` |
| `smoke` | Page allowlist plus page-level HTTP tests |
| `crawl` | Declarative link crawls, direct fetches, security path probes |

`config/matrix.json` defines `baseline-default` (all suites), one light case per SUT customization, and `config-overrides`. See [Matrix](docs/matrix.md).

## CI

`.github/workflows/ci.yml` runs `./doctor`, `./test:js`, and `./test:matrix` against `ultiorganizer@master` on every push to `main` and every PR. The job summary shows per-case results; the full `reports/` tree is uploaded as the `harness-reports` artifact. The SUT repository runs the same harness against its own PRs.

## Documentation

See [docs/README.md](docs/README.md). Agent-facing rules are in [AGENTS.md](AGENTS.md).
