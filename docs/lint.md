# PHP Syntax Lint

## Purpose

The `lint` suite runs PHP syntax checks against the prepared runtime SUT copy.

It answers one cheap question before heavier suites run:

> Do all SUT PHP files parse under the harness PHP version?

## Behavior

The suite runs after the normal runtime preparation flow:

1. copy the SUT into `.runtime/cases/<case-id>/sut`
2. inject the test config
3. recreate and load the disposable database
4. run `php -l` for each discovered PHP file in the runtime SUT copy

The production checkout remains read-only from the harness perspective.

## Commands

Run only lint:

```sh
./test:lint
```

Run lint through the generic suite command:

```sh
./test:case baseline-default --suites lint
```

`./test:quick` runs `lint`, `unit`, and `integration`.

## Artifacts

Each lint run writes:

- `reports/cases/<case-id>/<run-id>/logs/lint.log`
- one suite result in `summary/summary.json`

When syntax lint fails, the summary uses `php_lint_failure` and records the first failing PHP file in `first_failed_test`.
