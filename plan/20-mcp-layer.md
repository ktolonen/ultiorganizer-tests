# MCP Layer Plan

## Purpose

The MCP layer exists to support a development agent that needs to validate a feature branch before a PR is created.

The MCP layer must not contain business logic for test orchestration. It must wrap stable script entrypoints already provided by the repository.

## Implementation Direction

- MCP implementation language: Python
- MCP server runs inside the containerized test environment
- MCP tools call local scripts and return structured summaries plus artifact locations

## Required MCP Capabilities

### Matrix discovery

- list available matrix cases
- show metadata for a matrix case

### Execution

- run one matrix case
- run one suite within a matrix case
- run one filtered test subset
- rerun failed tests for a given case if supported later

### Reporting and diagnostics

- fetch latest summary
- fetch summary for a named case
- fetch logs for a named case
- return artifact paths for JUnit, summary output, and raw logs

## Expected Tool Shapes

Initial MCP tool set:

- `matrix_list`
- `matrix_run`
- `suite_run`
- `test_run`
- `report_latest`
- `report_case`
- `logs_case`

## Input Contract

Each execution-oriented MCP tool should accept enough context to support feature-branch validation:

- SUT path or worktree path
- matrix case id
- suite id where relevant
- test filter where relevant
- optional report output directory suffix or run label

## Output Contract

Each MCP tool should return structured data with at least:

- overall status
- matrix case id
- customization id
- configuration profile id
- started and finished timestamps
- per-suite summary
- artifact paths
- failure summary when relevant

## Branch Validation Flow

1. A development agent is pointed at a feature branch or worktree.
2. The agent asks MCP to run the default validation case or a targeted case.
3. MCP invokes the normal repo scripts inside the container.
4. Reports and logs are written to the standard artifact locations.
5. MCP returns a structured summary and artifact paths.
6. The agent uses those results to decide whether code is ready for a PR.

## Non-Goals

- MCP-specific orchestration paths that bypass the scripts
- GUI-driven reporting
- replacing the need for shell entrypoints

## Acceptance Criteria

The MCP layer is sufficient when:

- a development agent can trigger the PoC case through MCP
- the result includes pass/fail at suite and case level
- failures include direct links or paths to logs and reports
- the same underlying scripts can be run outside MCP with equivalent behavior
