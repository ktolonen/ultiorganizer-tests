# Client JavaScript Tests

Most of the harness exercises the PHP SUT through PHPUnit and HTTP. A small
amount of behaviour, though, lives in shipped client-side JavaScript under the
SUT's `script/` directory. These tests cover that logic directly.

## Scope

- Pure client logic only: no database, browser, or test container.
- Each test loads a SUT `script/*.js` file under a minimal DOM/`window` stub and
  a controllable fake clock (`Date.now`), then asserts behaviour.
- The SUT JavaScript is plain ES5 (per the SUT's ESLint config), so it loads in
  host Node with `require()` and no transpilation.

## Running

```
./test:js [SUT_PATH]
```

`SUT_PATH` resolves from the first argument, the `SUT_PATH` environment
variable, or the sibling `../ultiorganizer` checkout. The entrypoint runs every
test under `tests/Js/` with host Node and exits non-zero on the first failing
assertion.

This path is intentionally separate from the Docker/PHPUnit suites: it needs no
database or runtime SUT copy, only Node and the SUT's `script/` sources. It is
therefore not part of `./test:matrix`; run it explicitly (or wire it into CI as
a standalone step).

## Current tests

- `tests/Js/timekeeper-engine.test.js`: the Timekeeper timer engine
  (`script/timekeeper.js`). Pins the time + textual-instruction signal model
  (highest-time signal is the red "play"), the action-specific behaviours
  (Start of game starts the game clock; Call or discussion repeats its final
  signal), and the WFDF before-pull timeout anchoring (A5.5.2: end-of-timeout is
  measured from the start of the point, not the call). The representative
  template in the test mirrors the seeded WFDF default.
- `tests/Js/scorekeeper-clock.test.js`: the Scorekeeper game clock and
  double-submit guard (`script/scorekeeper.js`). Pins the Date.now()-delta
  drift model (paused freezes elapsed; ongoing derives it fresh on every read
  instead of counting a `setInterval`, so a suspended screen can't fall
  behind), the `roundedTime()` 5s-rounding/60→minute-carry rule, every
  `serverSampleClientMs()` anchor-acceptance/rejection branch (missing
  Performance API, no navigation entries, a future anchor, a stale
  >300000ms-old anchor, and the valid case that credits transfer time), and
  the double-submit guard (deferred, not synchronous, control disabling; a
  second submit on a still-busy form is blocked; a persisted `pageshow`
  restores the form).

## Adding a test

Add a `tests/Js/<name>.test.js` file that stubs only the globals the target
script touches (`document`, `window`, `navigator`, `Date.now`), `require()`s the
SUT script via the resolved SUT path, and prints `PASS`/`FAIL` lines while
tracking a failure count for the process exit code. Keep tests dependency-free
(no npm packages) so `./test:js` stays a plain Node invocation.
