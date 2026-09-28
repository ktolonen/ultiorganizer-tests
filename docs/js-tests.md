# Client JavaScript Tests

`tests/Js/*.test.js` cover logic in the SUT's shipped client-side JavaScript (`script/`). They run in host Node with no DB, browser, or container. Each test loads a SUT script under a minimal DOM/`window` stub and a fake `Date.now`. The SUT JS is plain ES5, so `require()` works without transpiling.

```sh
./test:js [SUT_PATH]
```

The SUT path comes from `$SUT_PATH`, then the first argument, then `../ultiorganizer`. Because these tests are not part of `./test:matrix`, CI runs them as a separate step.

## Current Tests

- `timekeeper-engine.test.js` (`script/timekeeper.js`): the signal model (the highest-time signal is the red "play"), action-specific behavior, and WFDF A5.5.2 before-pull timeout anchoring, measured from the start of the point.
- `scorekeeper-clock.test.js` (`script/scorekeeper.js`): the `Date.now()`-delta clock (a suspended screen cannot drift), the `roundedTime()` rounding and carry rule, every `serverSampleClientMs()` anchor-acceptance branch, and the double-submit guard.

## Adding A Test

Stub only the globals the script touches, `require()` the script from the resolved SUT path, and print `PASS`/`FAIL` lines. Exit non-zero on failure. Use no npm dependencies.
