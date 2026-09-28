# Smoke Testing

The `smoke` suite is every PHPUnit test under `tests/Smoke/`, run over HTTP against the served runtime copy. It has two parts.

## Page Allowlist

`PublicPagesSmokeTest` requests each `smoke_pages` entry (`id`, `query`) of the case through `index.php`. A page fails on:

- a non-`200` status
- a PHP fatal error, parse error, warning, or notice in the response
- a PHP warning or notice newly written to the Apache error log

A failure is reported with the page id, query, status, a response snippet, and an Apache log excerpt.

## Page-Level Tests

The remaining classes pin logic that lives in page files rather than `lib/`, so the in-process suites cannot reach it. Current themes:

- output escaping of reflected parameters and stored names, anonymous and logged in
- cross-event checks, where an id from another event must be refused
- rights checks before a page acts
- page content: gameplay replay, standings, team cards, series status
- login-gated editors such as `ScoresheetPageTest` and `ScoresheetHistoryPageTest`, which log in as the fixture superadmin and restore any rows they change

Rules for these tests:

- Assert only locale-independent output (ids, CSS classes, links, injected markers), because `config-overrides` renders in `fi_FI`.
- Flush the persistent cache before each request that should see a DB write.

To test a change that lands while the page is still validating a request, stall the request at a known query: hold `LOCK TABLES <table> WRITE` on a second connection, send the POST over a raw socket, poll `information_schema.PROCESSLIST` until the page's query is `Waiting%`, write the competing change, then `UNLOCK TABLES` and read the response. `ScoresheetPageTest::testChangeLandingDuringValidationIsAConflict` is the reference.

Failures are classified `smoke_http_runtime_failure`. Use `crawl` for broad discovery rather than growing the allowlist into a crawler.
