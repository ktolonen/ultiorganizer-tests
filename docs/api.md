# REST API Contract Testing

The `api` suite (`tests/Api/`) checks the versioned JSON API under `/api/v1`: authentication, visibility rules, and fixture-backed responses.

The baseline fixture marks `HRN2026` API-public and seeds the token `harness-api-token`, scoped to `HRN2026`.

Covered: `GET /api/v1/openapi`; `401` for missing and invalid tokens; `events`, `teams`, `divisions`, and `games` for `event=HRN2026`; `gameplay?game=700`; plus season-scoped token, pool visibility, hidden-time, and spirit-visibility rules.

The scorekeeping app API cases (`ScorekeepingApi*Test`, base class `tests/Support/ScorekeepingApiTestCase.php`) drive discovery, entry context and the complete upload over HTTP with their own app tokens (ids 910-914) and scorekeeping links (920-923). They move games 700 and 701 and their reservations to today in the event timezone and restore everything in `tearDown()`. A case skips when the SUT's OpenAPI document does not list the endpoints, never on a 5xx. They cover the auth matrix, the entry-context shape, upload preconditions and validation, idempotent replay, history attribution, the forced snapshot (restored with history disabled) and failure handling: a `BEFORE INSERT` trigger on `uo_api_receipt` injects a failure after every write to prove data, history and receipt roll back together, and a trigger signalling error 1213 exercises the deadlock retry and the 503 `busy` answer. Those two cases skip if the database user cannot create triggers.

```sh
./test:api
```

Output is `logs/api.log` and `junit/api.xml`. Failures are `phpunit_test_failure`, with an `API_FAILURE` payload when the response or the Apache log shows a PHP runtime error.

## Fuzzing

`tests/Api/ApiFuzzTest.php` fuzzes every operation listed in the SUT's `api/openapi.json` (one test per operation, so a new endpoint is picked up automatically). It builds requests from the request schemas, then mutates them: wrong types, `null`, boundary and huge numbers, unicode, long strings, missing and extra fields, deep nesting, invalid JSON, a wrong content type and broken credentials. It uses the scorekeeping fixture's valid credentials by default so bodies reach the handlers, and resets the fixture per operation.

Per response it asserts: no 5xx (a `503 busy` is allowed), no leaked PHP diagnostics, a JSON object body, and the `Error` envelope on every 4xx. Responses that Apache generates itself (encoded slashes, oversized lines) skip the envelope check because the SUT never ran.

```sh
UO_FUZZ_SEED=42 UO_FUZZ_ITERATIONS=400 ./test:api --test-filter ApiFuzzTest
```

`UO_FUZZ_SEED` replays a run (a failure prints its seed, iteration and the full request); without it each run picks a new seed. `UO_FUZZ_ITERATIONS` is requests per operation, default 60. Values and headers are capped at 2000 characters.
