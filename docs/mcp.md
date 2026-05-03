# MCP

## Purpose

The repository exposes a thin MCP server so agents and tools can use the harness without reimplementing its orchestration.

The MCP server lives in `mcp/server.py`.

## Design Rule

MCP should stay a wrapper over the normal harness commands.

It should not:

- duplicate case execution logic
- duplicate report loading logic
- add a second orchestration path separate from `scripts/harness.py`

The canonical orchestration remains in:

- `scripts/harness.py`
- `scripts/container_runner.py`

## Current Tools

The MCP server currently exposes:

- `matrix_list`
- `matrix_run`
- `suite_run`
- `test_run`
- `report_latest`
- `report_case`
- `logs_case`

## Behavior

MCP tool calls translate to the same CLI operations used by local shell wrappers.

That means MCP results inherit the same:

- case model
- suite behavior, including `lint`, PHPUnit export/API contracts, smoke, and crawl suites
- failure classifications
- artifact paths
- report structure

## When To Extend

Extend MCP when an existing harness capability should become agent-accessible.

Do not extend MCP by inventing new behavior there first. Add the capability to the harness scripts, then expose it through MCP.
