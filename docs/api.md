# REST API Contract Testing

## Purpose

The `api` suite checks the versioned JSON API under `/api/v1`.

It covers authentication behavior and fixture-backed response contracts for the read-only public API.

## Fixture Contract

The baseline fixture marks `HRN2026` as API-public and creates one deterministic season-scoped API token:

```text
harness-api-token
```

The token is scoped to `HRN2026`.

## Coverage

The current suite checks:

- `GET /api/v1/openapi`
- missing-token `401` responses
- invalid-token `401` responses
- `GET /api/v1/events`
- `GET /api/v1/teams?event=HRN2026`
- `GET /api/v1/divisions?event=HRN2026`
- `GET /api/v1/games?event=HRN2026`
- `GET /api/v1/gameplay?game=700`

## Commands

Run only API contracts:

```sh
./test:api
```

Run through the generic case command:

```sh
./test:case baseline-default --suites api
```

The full default case includes `api`.

## Artifacts

Each API run writes:

- `reports/cases/<case-id>/<run-id>/logs/api.log`
- `reports/cases/<case-id>/<run-id>/junit/api.xml`
- one suite result in `summary/summary.json`

Failures use the normal `phpunit_test_failure` classification and include an `API_FAILURE` payload when the HTTP response or Apache/PHP log shows runtime errors.
