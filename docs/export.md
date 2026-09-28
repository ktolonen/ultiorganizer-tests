# Export Contract Testing

The `export` suite (`tests/Export/`) checks that the public `ext/` endpoints return output that parses and contains the baseline fixture data for season `HRN2026`, not merely a response.

Covered: `teamscsv.php`, `gamescsv.php`, `resultscsv.php`, `poolscsv.php`, `playerscsv.php`, `locationjson.php`, `locationxml.php`, `rss.php`.

```sh
./test:export
```

Output is `logs/export.log` and `junit/export.xml`. Failures are `phpunit_test_failure`; when the response or the Apache log shows a PHP runtime error, the message carries an `EXPORT_FAILURE` JSON payload.
