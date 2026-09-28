# MCP

`mcp/server.py` is a stdio JSON-RPC MCP server so agents can drive the harness. It exposes `matrix_list`, `matrix_run`, `suite_run`, `test_run`, `report_latest`, `report_case`, and `logs_case`.

Each tool call runs the same `scripts/harness.py` command as the shell wrappers, so results carry the same summary payloads, failure classes, and artifact paths.

## Rule

Keep it a wrapper. Never duplicate case execution or report loading in the server. To expose a new capability, add it to the harness scripts first, then wrap it.
