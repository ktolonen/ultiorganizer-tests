# Local Workflow

## Purpose

This repository is designed for local and worktree-based validation of Ultiorganizer changes.

The usual workflow is:

1. point the harness at a local checkout or worktree
2. run one case or one suite
3. inspect reports and logs

## Common Commands

Environment check:

```sh
./doctor
```

Default day-to-day validation:

```sh
./test:quick
```

Only PHP syntax lint:

```sh
./test:lint
```

Only export endpoint contracts:

```sh
./test:export
```

Only REST API contracts:

```sh
./test:api
```

Full default case:

```sh
./test:case baseline-default
```

Only crawl coverage:

```sh
./test:crawl
```

Latest summary:

```sh
./report:latest
```

Per-file lib catalog refresh:

```sh
./libtest:catalog-refresh
```

Per-file lib test gap report:

```sh
./libtest:missing
```

Run one per-file lib test:

```sh
./libtest:run --lib-file common.functions.php
```

## Alternate SUT Checkouts

The default SUT path is `../ultiorganizer`.

You can point the harness at another checkout or worktree with `--sut-path`.

Example:

```sh
./test:case baseline-default --sut-path /path/to/other/ultiorganizer
```

## Branch And PR Context

The harness records SUT git context with each run.

This is useful for:

- local branch work
- PR worktrees
- keeping separate latest pointers by context label

Examples:

```sh
./test:quick --sut-path ../ultiorganizer
./report:latest --context-label branch-my-feature
```

```sh
./test:case baseline-default \
  --sut-path ../ultiorganizer-pr-123 \
  --pr-number 123 \
  --pr-head-ref feature/my-change \
  --pr-base-ref main
```

## Practical Use

Use `test:quick` for frequent local feedback. It runs PHP syntax lint before the unit and integration suites.

Use `test:case` when you want the full configured case, including export contracts, API contracts, runtime HTTP checks, and crawl plans.

Use `report:case` and `logs:case` when a failure needs artifact inspection instead of just terminal output.

`logs:case` now also includes the run-level Apache/PHP error-log artifact. Check that path when a request failed without a clear PHPUnit assertion message or when runtime warnings only appeared in Apache logs.

Use `libtest:catalog-refresh`, `libtest:missing`, `libtest:scaffold`, and `libtest:run` when the change is centered on one top-level `lib/*.php` file rather than a broad harness run.
