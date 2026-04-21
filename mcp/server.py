#!/usr/bin/env python3

from __future__ import annotations

import json
import subprocess
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
HARNESS = ROOT / "scripts" / "harness.py"


def send(message: dict) -> None:
    sys.stdout.write(json.dumps(message) + "\n")
    sys.stdout.flush()


def extend_context_args(args: list[str], arguments: dict) -> list[str]:
    if arguments.get("sut_path"):
        args.extend(["--sut-path", arguments["sut_path"]])
    if arguments.get("run_label"):
        args.extend(["--run-label", arguments["run_label"]])
    if arguments.get("context_label"):
        args.extend(["--context-label", arguments["context_label"]])
    if arguments.get("pr_number"):
        args.extend(["--pr-number", arguments["pr_number"]])
    if arguments.get("pr_head_ref"):
        args.extend(["--pr-head-ref", arguments["pr_head_ref"]])
    if arguments.get("pr_base_ref"):
        args.extend(["--pr-base-ref", arguments["pr_base_ref"]])
    return args


def extend_report_args(args: list[str], arguments: dict) -> list[str]:
    if arguments.get("context_label"):
        args.extend(["--context-label", arguments["context_label"]])
    return args


def tool_definitions() -> list[dict]:
    return [
        {
            "name": "matrix_list",
            "description": "List available matrix cases.",
            "inputSchema": {"type": "object", "properties": {}},
        },
        {
            "name": "matrix_run",
            "description": "Run a full matrix case.",
            "inputSchema": {
                "type": "object",
                "properties": {
                    "case_id": {"type": "string"},
                    "sut_path": {"type": "string"},
                    "run_label": {"type": "string"},
                    "context_label": {"type": "string"},
                    "pr_number": {"type": "string"},
                    "pr_head_ref": {"type": "string"},
                    "pr_base_ref": {"type": "string"},
                },
                "required": ["case_id"],
            },
        },
        {
            "name": "suite_run",
            "description": "Run one suite in a matrix case.",
            "inputSchema": {
                "type": "object",
                "properties": {
                    "case_id": {"type": "string"},
                    "suite": {"type": "string"},
                    "sut_path": {"type": "string"},
                    "run_label": {"type": "string"},
                    "context_label": {"type": "string"},
                    "pr_number": {"type": "string"},
                    "pr_head_ref": {"type": "string"},
                    "pr_base_ref": {"type": "string"},
                },
                "required": ["case_id", "suite"],
            },
        },
        {
            "name": "test_run",
            "description": "Run a filtered subset of tests within a case.",
            "inputSchema": {
                "type": "object",
                "properties": {
                    "case_id": {"type": "string"},
                    "suite": {"type": "string"},
                    "test_filter": {"type": "string"},
                    "sut_path": {"type": "string"},
                    "run_label": {"type": "string"},
                    "context_label": {"type": "string"},
                    "pr_number": {"type": "string"},
                    "pr_head_ref": {"type": "string"},
                    "pr_base_ref": {"type": "string"},
                },
                "required": ["case_id", "suite", "test_filter"],
            },
        },
        {
            "name": "report_latest",
            "description": "Return the latest summary.",
            "inputSchema": {"type": "object", "properties": {"context_label": {"type": "string"}}},
        },
        {
            "name": "report_case",
            "description": "Return the latest summary for a case.",
            "inputSchema": {
                "type": "object",
                "properties": {
                    "case_id": {"type": "string"},
                    "context_label": {"type": "string"},
                },
                "required": ["case_id"],
            },
        },
        {
            "name": "logs_case",
            "description": "Return log paths for a case.",
            "inputSchema": {
                "type": "object",
                "properties": {
                    "case_id": {"type": "string"},
                    "context_label": {"type": "string"},
                },
                "required": ["case_id"],
            },
        },
    ]


def run_harness(args: list[str]) -> dict:
    result = subprocess.run(
        [sys.executable, str(HARNESS)] + args,
        cwd=ROOT,
        text=True,
        capture_output=True,
        check=False,
    )
    stdout = result.stdout.strip()
    if stdout:
        try:
            payload = json.loads(stdout)
        except json.JSONDecodeError:
            payload = {"stdout": stdout}
    else:
        payload = {}

    if result.returncode not in (0, 1):
        raise RuntimeError(result.stderr or result.stdout or "Harness command failed")

    return payload


def handle_tool_call(name: str, arguments: dict) -> dict:
    if name == "matrix_list":
        matrix = json.loads((ROOT / "config" / "matrix.json").read_text())
        return {"status": "ok", "cases": matrix["cases"]}
    if name == "matrix_run":
        args = ["case", "--case-id", arguments["case_id"]]
        return run_harness(extend_context_args(args, arguments))
    if name == "suite_run":
        args = ["suite", "--case-id", arguments["case_id"], "--suite", arguments["suite"]]
        return run_harness(extend_context_args(args, arguments))
    if name == "test_run":
        args = [
            "suite",
            "--case-id",
            arguments["case_id"],
            "--suite",
            arguments["suite"],
            "--test-filter",
            arguments["test_filter"],
        ]
        return run_harness(extend_context_args(args, arguments))
    if name == "report_latest":
        return run_harness(extend_report_args(["report-latest"], arguments))
    if name == "report_case":
        return run_harness(extend_report_args(["report-case", "--case-id", arguments["case_id"]], arguments))
    if name == "logs_case":
        return run_harness(extend_report_args(["logs-case", "--case-id", arguments["case_id"]], arguments))
    raise RuntimeError(f"Unknown tool: {name}")


def main() -> int:
    for line in sys.stdin:
        if not line.strip():
            continue
        request = json.loads(line)
        method = request.get("method")
        req_id = request.get("id")

        try:
            if method == "initialize":
                send(
                    {
                        "jsonrpc": "2.0",
                        "id": req_id,
                        "result": {
                            "protocolVersion": "2024-11-05",
                            "serverInfo": {"name": "ultiorganizer-test-harness", "version": "0.1.0"},
                            "capabilities": {"tools": {}},
                        },
                    }
                )
            elif method == "tools/list":
                send({"jsonrpc": "2.0", "id": req_id, "result": {"tools": tool_definitions()}})
            elif method == "tools/call":
                params = request.get("params", {})
                payload = handle_tool_call(params.get("name", ""), params.get("arguments", {}))
                send(
                    {
                        "jsonrpc": "2.0",
                        "id": req_id,
                        "result": {
                            "content": [
                                {
                                    "type": "text",
                                    "text": json.dumps(payload, indent=2),
                                }
                            ]
                        },
                    }
                )
            else:
                send({"jsonrpc": "2.0", "id": req_id, "result": {}})
        except Exception as exc:
            send({"jsonrpc": "2.0", "id": req_id, "error": {"code": -32000, "message": str(exc)}})
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
