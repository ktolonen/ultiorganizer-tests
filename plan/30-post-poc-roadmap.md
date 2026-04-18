# Post-PoC Roadmap

## Objective

After the PoC proves the harness works for one full path, extend it into a maintainable matrix-based validation system.

## Phase 1: Stabilize the PoC

- harden bootstrap and environment checks
- reduce flakiness in DB setup and teardown
- make report output consistent across repeated runs
- document default local development commands

## Phase 2: Expand Matrix Coverage

- add more customization targets beyond `cust/default`
- add more configuration profiles
- define a canonical matrix manifest format
- allow quick runs for one case and broader runs for multiple cases

## Phase 3: Expand Test Coverage

- add more unit tests for low-coupled helpers
- add more integration tests for important DB-backed logic
- widen smoke coverage to more public pages
- add selected authenticated pages once bootstrap and fixture setup support them safely

## Phase 4: Improve Developer Workflow

- add presets such as `quick`, `default`, and `broad`
- support targeted reruns for failed suites or named tests
- improve MCP summaries for faster diagnosis
- attach branch or worktree identifiers to reports

## Phase 5: Optional Hardening

- add coverage reporting if it becomes useful
- add scheduled or manual release validation runs
- introduce stricter gating for risky changes
- consider browser-based checks only if page smoke tests prove insufficient

## Exit Conditions for PoC

The work should move from PoC to broader implementation only after:

- one full case is reliable across repeated runs
- basic reports are readable and attributable
- MCP branch validation works for the default case
- the test harness can be operated without hand-editing config for each run
