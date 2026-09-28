# REST API Contract Testing

The `api` suite (`tests/Api/`) checks the versioned JSON API under `/api/v1`: authentication, visibility rules, and fixture-backed responses.

The baseline fixture marks `HRN2026` API-public and seeds the token `harness-api-token`, scoped to `HRN2026`.

Covered: `GET /api/v1/openapi`; `401` for missing and invalid tokens; `events`, `teams`, `divisions`, and `games` for `event=HRN2026`; `gameplay?game=700`; plus season-scoped token, pool visibility, hidden-time, and spirit-visibility rules.

```sh
./test:api
```

Output is `logs/api.log` and `junit/api.xml`. Failures are `phpunit_test_failure`, with an `API_FAILURE` payload when the response or the Apache log shows a PHP runtime error.
