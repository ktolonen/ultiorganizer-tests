# Lib Test Triage

When a per-file lib test fails after an SUT change, classify the failure before touching either the code or the test.

| Outcome | When |
|---|---|
| `implementation_regression` | The change broke previously valid behavior |
| `expected_behavior_changed` | Behavior clearly changed on purpose, so the expectation is stale |
| `test_bug` | The assertion or setup is wrong regardless of the change |
| `ambiguous` | Fixture data, loader assumptions, or output are not strong enough to decide; name the missing evidence |

Never silently turn a regression into a test update.

## Workflow

1. `./libtest:triage-status` lists changed lib files; `--lib-file <f>` shows one.
2. Run the narrowest command: `./libtest:run --lib-file <f>`.
3. Compare the SUT diff, the failure output, and the assertions.
4. Record the result in the catalog entry.

## Catalog Fields

- `triage_status` is `triaged` or `untriaged`.
- `triage_notes` is free text. Record the outcome there, along with known coverage ceilings such as `exit()` branches or locale-dependent lines.
