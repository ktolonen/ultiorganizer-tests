# Export Contract Testing

## Purpose

The `export` suite checks public machine-readable endpoints under `ext/`.

It exists to prove more than "the URL responds": exported CSV, JSON, XML, and RSS output must parse and include deterministic fixture data.

## Coverage

The current suite checks:

- `ext/teamscsv.php`
- `ext/gamescsv.php`
- `ext/resultscsv.php`
- `ext/poolscsv.php`
- `ext/playerscsv.php`
- `ext/locationjson.php`
- `ext/locationxml.php`
- `ext/rss.php`

The assertions use the `baseline` fixture pack and the `HRN2026` season.

## Commands

Run only export contracts:

```sh
./test:export
```

Run through the generic case command:

```sh
./test:case baseline-default --suites export
```

The full default case includes `export`.

## Artifacts

Each export run writes:

- `reports/cases/<case-id>/<run-id>/logs/export.log`
- `reports/cases/<case-id>/<run-id>/junit/export.xml`
- one suite result in `summary/summary.json`

Failures use the normal `phpunit_test_failure` classification and include an `EXPORT_FAILURE` payload when the HTTP response or Apache/PHP log shows runtime errors.
