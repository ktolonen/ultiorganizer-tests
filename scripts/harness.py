#!/usr/bin/env python3

from __future__ import annotations

import argparse
import fcntl
import json
import os
import shutil
import subprocess
import sys
import time
from contextlib import contextmanager
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
REPORTS_ROOT = ROOT / "reports"
MATRIX_CONFIG = ROOT / "config" / "matrix.json"
LIB_TEST_CATALOG = ROOT / "config" / "lib-test-catalog.json"
LOCK_PATH = ROOT / ".runtime" / "harness.lock"
SIBLING_DEFAULT_SUT_PATH = (ROOT.parent / "ultiorganizer").resolve()
REQUIRED_SUT_PATHS = [
    "index.php",
    "lib/database.php",
    "sql/ultiorganizer.sql",
    "cust/default",
]
CONTAINER_ENV_PASSTHROUGH_PREFIXES = ("WGET_", "UO_CRAWL_")
SUITE_CHOICES = ["lint", "unit", "integration", "export", "api", "smoke", "crawl"]
LIB_TEST_SUITES = ["unit", "integration"]
LIB_TEST_DB_BACKED_FILES = {
    "accreditation.functions.php",
    "api.functions.php",
    "club.functions.php",
    "comment.functions.php",
    "configuration.functions.php",
    "country.functions.php",
    "data.functions.php",
    "database.maintenance.php",
    "database.php",
    "game.functions.php",
    "location.functions.php",
    "logging.functions.php",
    "player.functions.php",
    "pool.functions.php",
    "reservation.functions.php",
    "season.functions.php",
    "seasonpoints.functions.php",
    "series.functions.php",
    "session.functions.php",
    "spirit.functions.php",
    "standings.functions.php",
    "statistical.functions.php",
    "swissdraw.functions.php",
    "team.functions.php",
    "timetable.functions.php",
    "url.functions.php",
    "user.functions.php",
}
LIB_TEST_LOAD_PROFILE_OVERRIDES = {
    "common.functions.php": "bootstrap_only",
    "configuration.functions.php": "database_with_common",
    "country.functions.php": "database_only",
    "database.php": "bootstrap_only",
    "session.functions.php": "bootstrap_only",
    "standings.functions.php": "bootstrap_only",
    "team.functions.php": "team_stack",
    "user.functions.php": "database_only",
}
LIB_TEST_STRATEGY_OVERRIDES = {
    "auth.guard.php": "bootstrap_guard",
    "database.php": "bootstrap_runtime",
    "include_only.guard.php": "bootstrap_guard",
    "view.guard.php": "bootstrap_guard",
}
LIB_TEST_NOTES = {
    "common.functions.php": "Stable utility-heavy target for the first pure-helper checkpoint.",
    "configuration.functions.php": "Pilot tuning showed that page-title reads also need common helper loading because utf8entities() is not declared inside the file.",
    "country.functions.php": "Representative DB-backed helper target for baseline fixture assertions.",
    "database.php": "Treat as bootstrap/runtime coverage first; avoid deep DB side effects until a dedicated checkpoint.",
    "include_only.guard.php": "Guard behavior should be validated with isolated include tests instead of broad runtime assertions.",
    "season.functions.php": "Feasible read-only target; keep first-pass coverage on deterministic season, pool, and reservation reads.",
    "session.functions.php": "Feasible request/session helper target even though the catalog still keeps it in the integration suite for now.",
    "standings.functions.php": "Prefer pure ranking helper coverage first; defer DB-driven standing resolution flows.",
    "team.functions.php": "Mixed legacy-heavy pilot target; keep first-pass assertions on stable fixture reads instead of mutation-heavy branches.",
    "logging.functions.php": "Feasible shallow target; keep coverage on event-category enumeration and direct event-log inserts.",
    "url.functions.php": "Feasible read-only target; prefer fixture-backed URL reads and defer superadmin-only write paths.",
}


class HarnessError(RuntimeError):
    def __init__(self, classification: str, reason: str, details: dict | None = None):
        super().__init__(reason)
        self.classification = classification
        self.reason = reason
        self.details = details or {}


def load_matrix() -> dict:
    return json.loads(MATRIX_CONFIG.read_text())


def load_json(path: Path) -> dict:
    return json.loads(path.read_text())


def get_case(case_id: str) -> dict:
    for case in load_matrix()["cases"]:
        if case["id"] == case_id:
            return case
    raise SystemExit(f"Unknown case id: {case_id}")


def default_sut_path() -> str:
    if SIBLING_DEFAULT_SUT_PATH.is_dir():
        return str(SIBLING_DEFAULT_SUT_PATH)
    return str(SIBLING_DEFAULT_SUT_PATH)


def normalize_sut_path(sut_path: str) -> str:
    return str(Path(sut_path).expanduser().resolve())


def normalize_lib_file(lib_file: str) -> str:
    normalized = lib_file.strip()
    if normalized.startswith("lib/"):
        normalized = normalized[4:]
    return normalized


def top_level_lib_files(sut_path: str) -> list[str]:
    lib_root = Path(sut_path) / "lib"
    return sorted(path.name for path in lib_root.glob("*.php") if path.is_file())


def lib_subject_id(lib_file: str) -> str:
    return normalize_lib_file(lib_file).removesuffix(".php")


def lib_test_class_name(lib_file: str) -> str:
    pieces = []
    token = ""
    for char in lib_subject_id(lib_file):
        if char.isalnum():
            token += char
            continue
        if token:
            pieces.append(token)
            token = ""
    if token:
        pieces.append(token)
    return "".join(piece[0].upper() + piece[1:] for piece in pieces) + "LibTest"


def infer_lib_test_suite(lib_file: str) -> str:
    normalized = normalize_lib_file(lib_file)
    return "integration" if normalized in LIB_TEST_DB_BACKED_FILES else "unit"


def infer_lib_test_strategy(lib_file: str) -> str:
    normalized = normalize_lib_file(lib_file)
    if normalized in LIB_TEST_STRATEGY_OVERRIDES:
        return LIB_TEST_STRATEGY_OVERRIDES[normalized]
    if normalized.endswith(".guard.php"):
        return "bootstrap_guard"
    if infer_lib_test_suite(normalized) == "integration":
        return "fixture_backed"
    if normalized.endswith("Class.php"):
        return "class_unit"
    return "direct_helper"


def infer_lib_load_profile(lib_file: str) -> str:
    normalized = normalize_lib_file(lib_file)
    if normalized in LIB_TEST_LOAD_PROFILE_OVERRIDES:
        return LIB_TEST_LOAD_PROFILE_OVERRIDES[normalized]
    if normalized.endswith(".guard.php"):
        return "bootstrap_only"
    if infer_lib_test_suite(normalized) == "integration":
        return "database_only"
    return "bootstrap_only"


def lib_test_path(lib_file: str, suite: str) -> str:
    suite_dir = "Unit" if suite == "unit" else "Integration"
    return f"tests/{suite_dir}/Lib/{lib_test_class_name(lib_file)}.php"


def lib_test_entry(
    lib_file: str,
    *,
    existing_entry: dict | None = None,
    sut_path: str | None = None,
) -> dict:
    normalized = normalize_lib_file(lib_file)
    inferred_suite = infer_lib_test_suite(normalized)
    existing_test_path = (existing_entry or {}).get("test_path")
    existing_path_exists = bool(existing_test_path) and (ROOT / existing_test_path).is_file()

    if existing_path_exists:
        suite = (existing_entry or {}).get("test_suite") or inferred_suite
        if suite not in LIB_TEST_SUITES:
            suite = inferred_suite
        test_path = existing_test_path or lib_test_path(normalized, suite)
    else:
        suite = inferred_suite
        test_path = lib_test_path(normalized, suite)
    resolved_test_path = ROOT / test_path
    triage_status = (existing_entry or {}).get("triage_status") or "untriaged"
    triage_notes = (existing_entry or {}).get("triage_notes") or ""
    notes = (existing_entry or {}).get("notes") or LIB_TEST_NOTES.get(normalized, "")

    entry = {
        "lib_file": normalized,
        "lib_path": f"lib/{normalized}",
        "subject_id": lib_subject_id(normalized),
        "test_suite": suite,
        "test_path": test_path,
        "test_class": (existing_entry or {}).get("test_class") or lib_test_class_name(normalized),
        "strategy": (existing_entry or {}).get("strategy") or infer_lib_test_strategy(normalized),
        "load_profile": (existing_entry or {}).get("load_profile") or infer_lib_load_profile(normalized),
        "test_path_exists": resolved_test_path.is_file(),
        "status": "covered" if resolved_test_path.is_file() else "missing",
        "triage_status": triage_status,
        "triage_notes": triage_notes,
        "notes": notes,
    }
    if sut_path:
        entry["sut_path"] = str((Path(sut_path) / "lib" / normalized).resolve())
    return entry


def load_lib_test_catalog() -> dict:
    if not LIB_TEST_CATALOG.is_file():
        raise SystemExit("No lib test catalog found. Run `./libtest:catalog-refresh` first.")
    return load_json(LIB_TEST_CATALOG)


def build_lib_test_catalog(sut_path: str, existing_catalog: dict | None = None) -> dict:
    existing_entries = {
        entry["lib_file"]: entry
        for entry in (existing_catalog or {}).get("entries", [])
        if entry.get("lib_file")
    }
    entries = [
        lib_test_entry(lib_file, existing_entry=existing_entries.get(lib_file), sut_path=sut_path)
        for lib_file in top_level_lib_files(sut_path)
    ]
    return {
        "version": 1,
        "sut_path": normalize_sut_path(sut_path),
        "naming": {
            "subject_id": "lib filename without the .php suffix",
            "test_class_suffix": "LibTest",
            "test_path_pattern": "tests/<Unit|Integration>/Lib/<DerivedClassName>LibTest.php",
        },
        "entries": entries,
    }


def write_lib_test_catalog(catalog: dict) -> None:
    LIB_TEST_CATALOG.parent.mkdir(parents=True, exist_ok=True)
    LIB_TEST_CATALOG.write_text(json.dumps(catalog, indent=2) + "\n")


def changed_top_level_lib_files(sut_path: str) -> list[str]:
    result = subprocess.run(
        ["git", "-C", sut_path, "status", "--short", "--", "lib"],
        cwd=ROOT,
        text=True,
        capture_output=True,
        check=False,
    )
    changed: set[str] = set()
    for line in result.stdout.splitlines():
        if len(line) < 4:
            continue
        rel_path = line[3:].strip()
        if rel_path.startswith("lib/") and rel_path.count("/") == 1 and rel_path.endswith(".php"):
            changed.add(rel_path[4:])
    return sorted(changed)


def render_scaffolded_lib_test(entry: dict) -> str:
    load_line = f"        LegacyApp::loadLibFileUsingProfile('{entry['lib_file']}', '{entry['load_profile']}');"
    tear_down = ""
    if entry["test_suite"] == "integration":
        tear_down = """
    protected function tearDown(): void
    {
        LegacyApp::closeDatabaseConnection();
    }
"""

    return f"""<?php

declare(strict_types=1);

use PHPUnit\\Framework\\TestCase;
use UltiorganizerHarness\\Support\\LegacyApp;

final class {entry['test_class']} extends TestCase
{{
    protected function setUp(): void
    {{
        LegacyApp::resetRequestState();
{load_line}
    }}
{tear_down}
    public function testAddFirstMeaningfulAssertion(): void
    {{
        $this->markTestIncomplete('TODO: add the first focused assertion for lib/{entry["lib_file"]}.');
    }}
}}
"""


def sanitize_label(value: str) -> str:
    return "".join(char if char.isalnum() or char in "._-" else "-" for char in value).strip("-") or "run"


def git_output(sut_path: str, args: list[str]) -> str:
    result = subprocess.run(["git", "-C", sut_path] + args, cwd=ROOT, text=True, capture_output=True, check=False)
    if result.returncode != 0:
        return ""
    return result.stdout.strip()


def detect_sut_context(
    sut_path: str,
    *,
    context_label: str | None = None,
    pr_number: str | None = None,
    pr_head_ref: str | None = None,
    pr_base_ref: str | None = None,
) -> dict:
    normalized_path = normalize_sut_path(sut_path)
    branch = git_output(normalized_path, ["rev-parse", "--abbrev-ref", "HEAD"])
    commit_sha = git_output(normalized_path, ["rev-parse", "HEAD"])
    git_root = git_output(normalized_path, ["rev-parse", "--show-toplevel"])
    origin_url = git_output(normalized_path, ["remote", "get-url", "origin"])
    dirty = bool(git_output(normalized_path, ["status", "--short"]))
    inferred_label = context_label
    if not inferred_label and pr_number:
        inferred_label = f"pr-{pr_number}"
    if not inferred_label and branch and branch != "HEAD":
        inferred_label = f"branch-{branch}"
    if not inferred_label:
        inferred_label = Path(normalized_path).name

    return {
        "label": inferred_label,
        "type": "pull_request" if pr_number else "local_checkout",
        "pr_number": pr_number or "",
        "pr_head_ref": pr_head_ref or "",
        "pr_base_ref": pr_base_ref or "",
        "host_path": normalized_path,
        "git_root": git_root,
        "branch": branch,
        "commit_sha": commit_sha,
        "origin_url": origin_url,
        "dirty": dirty,
    }


def compose_env(sut_path: str) -> dict[str, str]:
    env = os.environ.copy()
    env["UO_SUT_HOST_PATH"] = normalize_sut_path(sut_path)
    return env


def forwarded_container_env() -> dict[str, str]:
    forwarded: dict[str, str] = {}
    for name, value in os.environ.items():
        if any(name.startswith(prefix) for prefix in CONTAINER_ENV_PASSTHROUGH_PREFIXES):
            forwarded[name] = value
    return forwarded


@contextmanager
def harness_lock():
    LOCK_PATH.parent.mkdir(parents=True, exist_ok=True)
    with LOCK_PATH.open("w", encoding="utf-8") as handle:
        fcntl.flock(handle.fileno(), fcntl.LOCK_EX)
        try:
            handle.write(str(os.getpid()) + "\n")
            handle.flush()
            yield
        finally:
            fcntl.flock(handle.fileno(), fcntl.LOCK_UN)


def run(cmd: list[str], *, env: dict[str, str] | None = None) -> subprocess.CompletedProcess[str]:
    return subprocess.run(cmd, cwd=ROOT, env=env, text=True, capture_output=True, check=False)


def docker_compose(args: list[str], sut_path: str) -> subprocess.CompletedProcess[str]:
    return run(["docker", "compose"] + args, env=compose_env(sut_path))


def container_exec(command: list[str], sut_path: str) -> subprocess.CompletedProcess[str]:
    uid = str(os.getuid())
    gid = str(os.getgid())
    base = [
        "docker",
        "compose",
        "exec",
        "-T",
        "-u",
        f"{uid}:{gid}",
        "-e",
        "HOME=/tmp",
        "php-test",
    ]
    return run(base + command, env=compose_env(sut_path))


def extract_output(result: subprocess.CompletedProcess[str]) -> str:
    return (result.stdout or "") + (result.stderr or "")


def sut_preflight_checks(sut_path: str) -> list[dict]:
    sut_root = Path(sut_path)
    checks: list[dict] = []

    if sut_root.is_dir():
        checks.append({"name": "sut_path_exists", "status": "passed", "details": sut_path})
    else:
        checks.append({"name": "sut_path_exists", "status": "failed", "details": sut_path})

    missing = [rel for rel in REQUIRED_SUT_PATHS if not (sut_root / rel).exists()]
    checks.append(
        {
            "name": "sut_required_files",
            "status": "passed" if not missing else "failed",
            "details": {"required": REQUIRED_SUT_PATHS, "missing": missing},
        }
    )
    return checks


def require_sut_preflight(sut_path: str) -> list[dict]:
    checks = sut_preflight_checks(sut_path)
    failures = [check for check in checks if check["status"] != "passed"]
    if failures:
        raise HarnessError(
            "preflight_failure",
            "SUT preflight checks failed",
            {"checks": checks},
        )
    return checks


def ensure_stack(sut_path: str) -> None:
    result = docker_compose(["up", "-d", "--build", "mariadb", "php-test"], sut_path)
    if result.returncode != 0:
        raise HarnessError(
            "container_startup_failure",
            "Docker Compose could not start mariadb and php-test",
            {"output": extract_output(result)},
        )


def ensure_dependencies(sut_path: str) -> None:
    result = container_exec(["python3", "/workspace/scripts/container_runner.py", "ensure-deps"], sut_path)
    if result.returncode != 0:
        raise HarnessError(
            "container_startup_failure",
            "Composer dependencies could not be installed inside php-test",
            {"output": extract_output(result)},
        )


def failure_payload(
    *,
    case_id: str,
    sut_path: str,
    classification: str,
    reason: str,
    run_label: str | None = None,
    sut_context: dict | None = None,
    details: dict | None = None,
) -> dict:
    return {
        "status": "failed",
        "case_id": case_id,
        "sut_source": normalize_sut_path(sut_path),
        "sut_context": sut_context or {},
        "context_label": (sut_context or {}).get("label", ""),
        "run_label": run_label or "",
        "suite_results": [],
        "artifact_paths": {},
        "failure_classification": classification,
        "failure_reason": reason,
        "details": details or {},
    }


def invoke_case_runner(
    case_id: str,
    sut_path: str,
    suites: str | None = None,
    test_filter: str | None = None,
    run_label: str | None = None,
    sut_context: dict | None = None,
) -> dict:
    cmd = ["python3", "/workspace/scripts/container_runner.py", "run-case", "--case-id", case_id]
    if suites:
        cmd.extend(["--suites", suites])
    if test_filter:
        cmd.extend(["--test-filter", test_filter])
    if run_label:
        cmd.extend(["--run-label", run_label])

    env = compose_env(sut_path)
    if sut_context:
        env["UO_SUT_CONTEXT_JSON"] = json.dumps(sut_context)
    passthrough_env = forwarded_container_env()

    uid = str(os.getuid())
    gid = str(os.getgid())
    base = [
        "docker",
        "compose",
        "exec",
        "-T",
        "-u",
        f"{uid}:{gid}",
        "-e",
        "HOME=/tmp",
    ]
    for name, value in passthrough_env.items():
        base.extend(["-e", f"{name}={value}"])
    if sut_context:
        base.extend(["-e", f"UO_SUT_CONTEXT_JSON={json.dumps(sut_context)}"])
    result = run(base + ["php-test"] + cmd, env=env)
    stdout = result.stdout.strip()
    if not stdout:
        raise HarnessError(
            "container_startup_failure",
            "Case runner returned no JSON payload",
            {"output": extract_output(result)},
        )

    try:
        payload = json.loads(stdout)
    except json.JSONDecodeError as exc:
        raise HarnessError(
            "container_startup_failure",
            "Case runner returned invalid JSON",
            {"output": extract_output(result)},
        ) from exc

    if result.returncode not in (0, 1):
        raise HarnessError(
            payload.get("failure_classification", "container_startup_failure"),
            payload.get("failure_reason", "Case runner exited unexpectedly"),
            {"payload": payload, "output": extract_output(result)},
        )
    return payload


def run_case(
    case_id: str,
    sut_path: str,
    suites: str | None = None,
    test_filter: str | None = None,
    run_label: str | None = None,
    context_label: str | None = None,
    pr_number: str | None = None,
    pr_head_ref: str | None = None,
    pr_base_ref: str | None = None,
) -> dict:
    normalized_sut_path = normalize_sut_path(sut_path)
    sut_context = detect_sut_context(
        normalized_sut_path,
        context_label=context_label,
        pr_number=pr_number,
        pr_head_ref=pr_head_ref,
        pr_base_ref=pr_base_ref,
    )
    try:
        with harness_lock():
            require_sut_preflight(normalized_sut_path)
            ensure_stack(normalized_sut_path)
            ensure_dependencies(normalized_sut_path)
            return invoke_case_runner(
                case_id,
                normalized_sut_path,
                suites=suites,
                test_filter=test_filter,
                run_label=run_label,
                sut_context=sut_context,
            )
    except HarnessError as exc:
        return failure_payload(
            case_id=case_id,
            sut_path=normalized_sut_path,
            classification=exc.classification,
            reason=exc.reason,
            run_label=run_label,
            sut_context=sut_context,
            details=exc.details,
        )


def read_json(path: Path, missing_message: str) -> dict:
    if not path.is_file():
        raise SystemExit(missing_message)
    return json.loads(path.read_text())


def report_latest(context_label: str | None = None) -> dict:
    if context_label:
        context_label = sanitize_label(context_label)
        return read_json(
            REPORTS_ROOT / "summary" / "contexts" / context_label / "latest.json",
            f"No latest report found for context {context_label}",
        )
    return read_json(REPORTS_ROOT / "summary" / "latest.json", "No latest report found")


def report_case(case_id: str, context_label: str | None = None) -> dict:
    if context_label:
        context_label = sanitize_label(context_label)
        return read_json(
            REPORTS_ROOT / "cases" / case_id / "contexts" / context_label / "latest.json",
            f"No report found for case {case_id} and context {context_label}",
        )
    return read_json(REPORTS_ROOT / "cases" / case_id / "latest.json", f"No report found for case {case_id}")


def logs_case(case_id: str, context_label: str | None = None) -> dict:
    summary = report_case(case_id, context_label=context_label)
    logs: dict[str, str] = {}
    setup_result = summary.get("setup_result") or {}
    if setup_result.get("log_path"):
        logs["setup"] = setup_result["log_path"]
    apache_error_log = ((summary.get("runtime_logs") or {}).get("apache_error_log") or {}).get("artifact_path")
    if apache_error_log:
        logs["apache_error_log"] = apache_error_log
    for suite in summary.get("suite_results", []):
        logs[suite["suite"]] = suite["log_path"]
        if suite.get("suite") == "crawl":
            for plan in suite.get("crawl_plans", []):
                plan_id = plan.get("id", "crawl-plan")
                if plan.get("log_path"):
                    logs[f"crawl:{plan_id}"] = plan["log_path"]
    return {
        "case_id": case_id,
        "context_label": summary.get("context_label", ""),
        "status": summary["status"],
        "failure_classification": summary.get("failure_classification"),
        "logs": logs,
    }


def report_summary_files() -> list[Path]:
    return sorted(REPORTS_ROOT.glob("cases/*/*/summary/summary.json"))


def report_artifact_href(path_value: str | None) -> str:
    if not path_value:
        return ""
    normalized = path_value.replace("\\", "/")
    marker = "/reports/"
    if marker in normalized:
        return normalized.split(marker, 1)[1]
    reports_prefix = "reports/"
    if normalized.startswith(reports_prefix):
        return normalized[len(reports_prefix) :]
    return normalized


def report_host_path(path_value: str) -> Path:
    normalized = path_value.replace("\\", "/")
    marker = "/reports/"
    if marker in normalized:
        return REPORTS_ROOT / normalized.split(marker, 1)[1]
    reports_prefix = "reports/"
    if normalized.startswith(reports_prefix):
        return REPORTS_ROOT / normalized[len(reports_prefix) :]
    return Path(path_value)


def report_run_record(summary_path: Path) -> dict:
    summary = load_json(summary_path)
    run_root = summary_path.parents[1]
    run_id = run_root.name
    case_id = run_root.parent.name
    suite_results = summary.get("suite_results") or []
    totals = {
        "tests": sum(int(suite.get("tests") or 0) for suite in suite_results),
        "failures": sum(int(suite.get("failures") or 0) for suite in suite_results),
        "errors": sum(int(suite.get("errors") or 0) for suite in suite_results),
        "skipped": sum(int(suite.get("skipped") or 0) for suite in suite_results),
    }
    artifacts = dict(summary.get("artifact_paths") or {})
    artifacts["summary_json"] = str(summary_path)
    artifacts["summary_md"] = str(summary_path.with_suffix(".md"))
    artifact_links = {
        key: report_artifact_href(value)
        for key, value in artifacts.items()
        if isinstance(value, str) and value
    }
    for suite in suite_results:
        suite_name = suite.get("suite")
        if suite_name:
            artifact_links[f"{suite_name}_log"] = report_artifact_href(suite.get("log_path"))
            artifact_links[f"{suite_name}_junit"] = report_artifact_href(suite.get("junit_path"))
        if suite_name == "crawl":
            for plan in suite.get("crawl_plans", []):
                plan_id = plan.get("id", "crawl-plan")
                artifact_links[f"crawl:{plan_id}_log"] = report_artifact_href(plan.get("log_path"))

    return {
        "run_id": run_id,
        "case_id": case_id,
        "summary_path": str(summary_path.relative_to(REPORTS_ROOT)),
        "summary_href": report_artifact_href(str(summary_path)),
        "summary_md_href": report_artifact_href(str(summary_path.with_suffix(".md"))),
        "status": summary.get("status", "unknown"),
        "started_at": summary.get("started_at", ""),
        "finished_at": summary.get("finished_at", ""),
        "duration_seconds": round(float(summary.get("duration_seconds") or 0), 3)
        if summary.get("duration_seconds") is not None
        else None,
        "context_label": summary.get("context_label", ""),
        "run_label": summary.get("run_label", ""),
        "branch": summary.get("branch", ""),
        "commit_sha": summary.get("commit_sha", ""),
        "failure_classification": summary.get("failure_classification"),
        "failure_reason": summary.get("failure_reason"),
        "first_failed_test": summary.get("first_failed_test"),
        "failed_pages": summary.get("failed_pages") or [],
        "suites_run": summary.get("suites_run") or [],
        "suite_results": suite_results,
        "setup_result": summary.get("setup_result"),
        "runtime_logs": summary.get("runtime_logs"),
        "crawl_plans": summary.get("crawl_plans") or [],
        "totals": totals,
        "artifact_links": artifact_links,
        "summary": summary,
    }


def build_report_html(records: list[dict]) -> str:
    data_json = (
        json.dumps(records, ensure_ascii=False)
        .replace("&", "\\u0026")
        .replace("<", "\\u003c")
        .replace(">", "\\u003e")
    )
    return f"""<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ultiorganizer Test Reports</title>
  <style>
    :root {{
      color-scheme: light;
      --bg: #f6f7f9;
      --panel: #ffffff;
      --line: #d8dde6;
      --text: #17202a;
      --muted: #657386;
      --pass: #1f7a43;
      --fail: #b42318;
      --warn: #9a6700;
      --chip: #eef2f7;
      --accent: #2458a6;
    }}
    * {{ box-sizing: border-box; }}
    body {{
      margin: 0;
      background: var(--bg);
      color: var(--text);
      font: 14px/1.45 system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }}
    header {{
      border-bottom: 1px solid var(--line);
      background: var(--panel);
      padding: 18px 24px;
    }}
    h1, h2, h3 {{ margin: 0; letter-spacing: 0; }}
    h1 {{ font-size: 22px; }}
    h2 {{ font-size: 18px; }}
    h3 {{ font-size: 15px; }}
    main {{
      display: grid;
      grid-template-columns: minmax(420px, 0.95fr) minmax(480px, 1.25fr);
      gap: 18px;
      padding: 18px;
      max-width: 1680px;
      margin: 0 auto;
    }}
    .panel {{
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 8px;
      min-width: 0;
    }}
    .toolbar {{
      display: grid;
      grid-template-columns: 1fr 150px 150px;
      gap: 10px;
      padding: 14px;
      border-bottom: 1px solid var(--line);
    }}
    input, select {{
      width: 100%;
      min-height: 36px;
      border: 1px solid var(--line);
      border-radius: 6px;
      padding: 7px 9px;
      background: #fff;
      color: var(--text);
      font: inherit;
    }}
    table {{
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
    }}
    th, td {{
      border-bottom: 1px solid var(--line);
      padding: 9px 10px;
      text-align: left;
      vertical-align: top;
      overflow-wrap: anywhere;
    }}
    th {{
      color: var(--muted);
      font-size: 12px;
      font-weight: 650;
      background: #fbfcfe;
    }}
    tr.run-row {{ cursor: pointer; }}
    tr.run-row:hover, tr.run-row.active {{ background: #edf4ff; }}
    .status {{
      display: inline-flex;
      align-items: center;
      min-height: 22px;
      padding: 2px 8px;
      border-radius: 999px;
      font-weight: 700;
      font-size: 12px;
      text-transform: uppercase;
    }}
    .passed {{ color: var(--pass); background: #e8f5ed; }}
    .failed, .error {{ color: var(--fail); background: #fdeceb; }}
    .unknown {{ color: var(--warn); background: #fff5d6; }}
    .detail {{
      padding: 16px;
    }}
    .meta-grid {{
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 10px;
      margin: 14px 0;
    }}
    .metric {{
      border: 1px solid var(--line);
      border-radius: 8px;
      padding: 10px;
      background: #fbfcfe;
    }}
    .metric .label {{
      color: var(--muted);
      font-size: 12px;
      margin-bottom: 4px;
    }}
    .metric .value {{
      font-size: 18px;
      font-weight: 750;
      overflow-wrap: anywhere;
    }}
    .section {{
      margin-top: 16px;
      padding-top: 14px;
      border-top: 1px solid var(--line);
    }}
    .suite-list {{
      display: grid;
      gap: 8px;
    }}
    .suite {{
      border: 1px solid var(--line);
      border-radius: 8px;
      padding: 10px;
      background: #fff;
    }}
    .suite-head {{
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 8px;
    }}
    .kv {{
      display: grid;
      grid-template-columns: 160px 1fr;
      gap: 6px 12px;
      margin: 8px 0;
    }}
    .key {{ color: var(--muted); }}
    .chips {{
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
    }}
    .chip {{
      background: var(--chip);
      border: 1px solid var(--line);
      border-radius: 999px;
      padding: 3px 8px;
      font-size: 12px;
    }}
    a {{ color: var(--accent); text-decoration: none; }}
    a:hover {{ text-decoration: underline; }}
    pre {{
      max-height: 360px;
      overflow: auto;
      margin: 8px 0 0;
      padding: 12px;
      border: 1px solid var(--line);
      border-radius: 8px;
      background: #101828;
      color: #eef4ff;
      font-size: 12px;
    }}
    details {{ margin-top: 10px; }}
    summary {{ cursor: pointer; font-weight: 650; }}
    .empty {{
      padding: 24px;
      color: var(--muted);
    }}
    @media (max-width: 1040px) {{
      main {{ grid-template-columns: 1fr; }}
      .toolbar {{ grid-template-columns: 1fr; }}
      .meta-grid {{ grid-template-columns: repeat(2, minmax(0, 1fr)); }}
    }}
  </style>
</head>
<body>
  <header>
    <h1>Ultiorganizer Test Reports</h1>
  </header>
  <main>
    <section class="panel">
      <div class="toolbar">
        <input id="search" type="search" placeholder="Filter by run, case, branch, status, reason">
        <select id="statusFilter" aria-label="Status filter">
          <option value="">All statuses</option>
          <option value="passed">Passed</option>
          <option value="failed">Failed</option>
        </select>
        <select id="caseFilter" aria-label="Case filter"></select>
      </div>
      <div id="runs"></div>
    </section>
    <section class="panel detail" id="detail"></section>
  </main>
  <script id="report-data" type="application/json">{data_json}</script>
  <script>
    const records = JSON.parse(document.getElementById('report-data').textContent);
    const state = {{ selected: 0, query: '', status: '', caseId: '' }};
    const runsEl = document.getElementById('runs');
    const detailEl = document.getElementById('detail');
    const searchEl = document.getElementById('search');
    const statusEl = document.getElementById('statusFilter');
    const caseEl = document.getElementById('caseFilter');

    function esc(value) {{
      return String(value ?? '').replace(/[&<>"']/g, char => ({{
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
      }}[char]));
    }}

    function statusClass(status) {{
      return ['passed', 'failed', 'error'].includes(status) ? status : 'unknown';
    }}

    function statusBadge(status) {{
      const value = status || 'unknown';
      return `<span class="status ${{statusClass(value)}}">${{esc(value)}}</span>`;
    }}

    function shortSha(value) {{
      return value ? String(value).slice(0, 12) : '';
    }}

    function formatDate(value) {{
      if (!value) return '';
      const date = new Date(value);
      return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
    }}

    function matches(record) {{
      if (state.status && record.status !== state.status) return false;
      if (state.caseId && record.case_id !== state.caseId) return false;
      if (!state.query) return true;
      const haystack = [
        record.run_id, record.case_id, record.status, record.branch, record.context_label,
        record.run_label, record.failure_classification, record.failure_reason
      ].join(' ').toLowerCase();
      return haystack.includes(state.query.toLowerCase());
    }}

    function filteredRecords() {{
      return records.filter(matches);
    }}

    function renderRuns() {{
      const visible = filteredRecords();
      if (!visible.length) {{
        runsEl.innerHTML = '<div class="empty">No runs match the current filters.</div>';
        detailEl.innerHTML = '<div class="empty">Select a run to inspect details.</div>';
        return;
      }}
      if (!visible.includes(records[state.selected])) {{
        state.selected = records.indexOf(visible[0]);
      }}
      const rows = visible.map(record => {{
        const index = records.indexOf(record);
        const active = index === state.selected ? ' active' : '';
        const totals = record.totals || {{}};
        const reason = record.failure_reason || record.suites_run.join(', ');
        return `<tr class="run-row${{active}}" data-index="${{index}}">
          <td>${{statusBadge(record.status)}}</td>
          <td><strong>${{esc(record.run_id)}}</strong><br><span class="key">${{esc(formatDate(record.started_at))}}</span></td>
          <td>${{esc(record.case_id)}}<br><span class="key">${{esc(record.context_label || '')}}</span></td>
          <td>${{esc(record.branch || '')}}<br><span class="key">${{esc(shortSha(record.commit_sha))}}</span></td>
          <td>${{esc(totals.tests || 0)}} tests<br><span class="key">${{esc(reason || '')}}</span></td>
        </tr>`;
      }}).join('');
      runsEl.innerHTML = `<table>
        <thead><tr><th style="width:92px">Status</th><th>Run</th><th>Case</th><th>Branch</th><th>Result</th></tr></thead>
        <tbody>${{rows}}</tbody>
      </table>`;
      for (const row of runsEl.querySelectorAll('.run-row')) {{
        row.addEventListener('click', () => {{
          state.selected = Number(row.dataset.index);
          renderRuns();
          renderDetail();
        }});
      }}
      renderDetail();
    }}

    function link(label, href) {{
      return href ? `<a href="${{esc(href)}}">${{esc(label)}}</a>` : '';
    }}

    function renderKeyValues(items) {{
      return `<div class="kv">${{items.map(([key, value]) =>
        `<div class="key">${{esc(key)}}</div><div>${{value}}</div>`
      ).join('')}}</div>`;
    }}

    function renderSuite(suite, links) {{
      const name = suite.suite || 'suite';
      const failed = suite.failed_tests || [];
      const crawlPlans = suite.crawl_plans || [];
      return `<div class="suite">
        <div class="suite-head"><h3>${{esc(name)}}</h3>${{statusBadge(suite.status)}}</div>
        ${{renderKeyValues([
          ['Tests', esc(suite.tests ?? 0)],
          ['Failures', esc(suite.failures ?? 0)],
          ['Errors', esc(suite.errors ?? 0)],
          ['Skipped', esc(suite.skipped ?? 0)],
          ['Duration', esc((suite.duration_seconds ?? 0) + 's')],
          ['Artifacts', [link('log', links[name + '_log']), link('junit', links[name + '_junit'])].filter(Boolean).join(' | ') || '']
        ])}}
        ${{suite.failure_reason ? `<div><strong>Failure:</strong> ${{esc(suite.failure_reason)}}</div>` : ''}}
        ${{crawlPlans.length ? `<div class="suite-list">${{crawlPlans.map(plan => `
          <div class="suite">
            <div class="suite-head"><h3>${{esc(plan.id)}}</h3>${{statusBadge(plan.status)}}</div>
            ${{renderKeyValues([
              ['Type', esc(plan.type || '')],
              ['Exit code', esc(plan.exit_code ?? '')],
              ['Duration', esc((plan.duration_seconds ?? 0) + 's')],
              ['Log', link('log', links['crawl:' + plan.id + '_log'])],
              ['Artifacts', esc(plan.artifact_root || '')],
              ['Failure', esc(plan.failure_reason || '')]
            ])}}
            ${{plan.log_excerpt ? `<details open><summary>Log excerpt</summary><pre>${{esc(plan.log_excerpt)}}</pre></details>` : ''}}
          </div>
        `).join('')}}</div>` : ''}}
        ${{failed.length ? `<details open><summary>Failed tests</summary><pre>${{esc(JSON.stringify(failed, null, 2))}}</pre></details>` : ''}}
        ${{suite.log_excerpt ? `<details open><summary>Log excerpt</summary><pre>${{esc(suite.log_excerpt)}}</pre></details>` : ''}}
      </div>`;
    }}

    function renderDetail() {{
      const record = records[state.selected];
      if (!record) {{
        detailEl.innerHTML = '<div class="empty">Select a run to inspect details.</div>';
        return;
      }}
      const totals = record.totals || {{}};
      const links = record.artifact_links || {{}};
      const runtimeLog = ((record.runtime_logs || {{}}).apache_error_log || {{}});
      const failedPages = record.failed_pages || [];
      detailEl.innerHTML = `
        <div class="suite-head">
          <h2>${{esc(record.case_id)}} / ${{esc(record.run_id)}}</h2>
          ${{statusBadge(record.status)}}
        </div>
        <div class="meta-grid">
          <div class="metric"><div class="label">Tests</div><div class="value">${{esc(totals.tests || 0)}}</div></div>
          <div class="metric"><div class="label">Failures</div><div class="value">${{esc(totals.failures || 0)}}</div></div>
          <div class="metric"><div class="label">Errors</div><div class="value">${{esc(totals.errors || 0)}}</div></div>
          <div class="metric"><div class="label">Skipped</div><div class="value">${{esc(totals.skipped || 0)}}</div></div>
        </div>
        ${{renderKeyValues([
          ['Started', esc(formatDate(record.started_at))],
          ['Finished', esc(formatDate(record.finished_at))],
          ['Branch', esc(record.branch || '')],
          ['Commit', esc(record.commit_sha || '')],
          ['Context', esc(record.context_label || '')],
          ['Run label', esc(record.run_label || '')],
          ['Summary', [link('json', record.summary_href), link('markdown', record.summary_md_href)].filter(Boolean).join(' | ')],
          ['Failure class', esc(record.failure_classification || '')],
          ['Failure reason', esc(record.failure_reason || '')]
        ])}}
        <div class="section">
          <h3>Suites</h3>
          <div class="suite-list">${{(record.suite_results || []).map(suite => renderSuite(suite, links)).join('') || '<div class="empty">No suites were recorded.</div>'}}</div>
        </div>
        ${{failedPages.length ? `<div class="section"><h3>Failed Smoke Pages</h3><pre>${{esc(JSON.stringify(failedPages, null, 2))}}</pre></div>` : ''}}
        ${{runtimeLog.detected ? `<div class="section"><h3>Apache/PHP Error Log</h3>${{renderKeyValues([
          ['PHP issue detected', esc(runtimeLog.php_issue_detected)],
          ['Artifact', link('apache-error.log', links.apache_error_log)]
        ])}}${{runtimeLog.excerpt ? `<pre>${{esc(runtimeLog.excerpt)}}</pre>` : ''}}</div>` : ''}}
        <div class="section">
          <h3>Artifacts</h3>
          <div class="chips">${{Object.entries(links).map(([key, href]) => `<span class="chip">${{link(key, href)}}</span>`).join('')}}</div>
        </div>
        <div class="section">
          <details><summary>Raw Summary JSON</summary><pre>${{esc(JSON.stringify(record.summary, null, 2))}}</pre></details>
        </div>
      `;
    }}

    function populateCaseFilter() {{
      const cases = Array.from(new Set(records.map(record => record.case_id))).sort();
      caseEl.innerHTML = '<option value="">All cases</option>' + cases.map(caseId => `<option value="${{esc(caseId)}}">${{esc(caseId)}}</option>`).join('');
    }}

    searchEl.addEventListener('input', event => {{
      state.query = event.target.value;
      renderRuns();
    }});
    statusEl.addEventListener('change', event => {{
      state.status = event.target.value;
      renderRuns();
    }});
    caseEl.addEventListener('change', event => {{
      state.caseId = event.target.value;
      renderRuns();
    }});

    populateCaseFilter();
    renderRuns();
  </script>
</body>
</html>
"""


def write_report_html(output_path: Path | None = None) -> dict:
    output_path = output_path or REPORTS_ROOT / "index.html"
    records = [report_run_record(path) for path in report_summary_files()]
    records.sort(key=lambda record: (record.get("started_at") or "", record.get("run_id") or ""), reverse=True)
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(build_report_html(records))
    return {
        "status": "passed",
        "report_html": str(output_path),
        "run_count": len(records),
    }


def report_run_cleanup_candidates(case_id: str | None = None) -> list[dict]:
    candidates = []
    for summary_path in report_summary_files():
        run_root = summary_path.parents[1]
        run_case_id = run_root.parent.name
        if case_id and run_case_id != case_id:
            continue
        try:
            summary = load_json(summary_path)
        except json.JSONDecodeError:
            summary = {}
        candidates.append(
            {
                "case_id": run_case_id,
                "run_id": run_root.name,
                "run_root": run_root,
                "started_at": summary.get("started_at", ""),
                "status": summary.get("status", "unknown"),
            }
        )
    candidates.sort(key=lambda item: (item["started_at"], item["run_id"]), reverse=True)
    return candidates


def prune_stale_report_pointers(deleted_roots: set[Path], dry_run: bool = False) -> list[str]:
    stale = []
    for pointer in REPORTS_ROOT.glob("**/*.json"):
        if "/summary/" in pointer.as_posix() and pointer.name == "summary.json":
            continue
        if pointer.parent.name == "summary" and pointer.name == "summary.json":
            continue
        try:
            payload = load_json(pointer)
        except (json.JSONDecodeError, OSError):
            continue
        case_root = ((payload.get("artifact_paths") or {}).get("case_root") or "").strip()
        if not case_root:
            continue
        case_root_path = report_host_path(case_root)
        if case_root_path in deleted_roots or not case_root_path.exists():
            stale.append(str(pointer))
            if not dry_run:
                pointer.unlink()
    return stale


def clean_reports(
    *,
    keep: int,
    case_id: str | None = None,
    delete_all: bool = False,
    dry_run: bool = False,
    refresh_html: bool = True,
) -> dict:
    if keep < 0:
        raise SystemExit("--keep must be 0 or greater")
    candidates = report_run_cleanup_candidates(case_id=case_id)
    delete_after = 0 if delete_all else keep
    to_delete = candidates[delete_after:]
    deleted_roots = {item["run_root"] for item in to_delete}

    deleted = []
    for item in to_delete:
        deleted.append(
            {
                "case_id": item["case_id"],
                "run_id": item["run_id"],
                "status": item["status"],
                "started_at": item["started_at"],
                "path": str(item["run_root"]),
            }
        )
        if not dry_run:
            shutil.rmtree(item["run_root"])

    stale_pointers = prune_stale_report_pointers(deleted_roots, dry_run=dry_run)
    html_result = None
    if refresh_html and not dry_run:
        html_result = write_report_html()

    return {
        "status": "passed",
        "dry_run": dry_run,
        "case_id": case_id or "",
        "keep": 0 if delete_all else keep,
        "runs_seen": len(candidates),
        "runs_deleted": len(deleted),
        "deleted": deleted,
        "stale_pointers_removed": stale_pointers,
        "report_html": (html_result or {}).get("report_html"),
    }


def docker_check(sut_path: str) -> dict:
    result = run(["docker", "ps"], env=compose_env(sut_path))
    return {
        "name": "docker_daemon",
        "status": "passed" if result.returncode == 0 else "failed",
        "details": extract_output(result).strip(),
    }


def compose_services_check(sut_path: str) -> dict:
    result = docker_compose(["config", "--services"], sut_path)
    services = [line.strip() for line in result.stdout.splitlines() if line.strip()]
    passed = result.returncode == 0 and {"mariadb", "php-test"}.issubset(set(services))
    return {
        "name": "compose_services",
        "status": "passed" if passed else "failed",
        "details": {"services": services, "output": extract_output(result).strip()},
    }


def stack_ready_check(sut_path: str) -> dict:
    try:
        ensure_stack(sut_path)
    except HarnessError as exc:
        return {
            "name": "stack_ready",
            "status": "failed",
            "details": {"reason": exc.reason, **exc.details},
        }
    return {"name": "stack_ready", "status": "passed", "details": "mariadb and php-test are running"}


def mariadb_ping_check(sut_path: str) -> dict:
    result = None
    details = ""
    for _ in range(20):
        result = run(
            [
                "docker",
                "compose",
                "exec",
                "-T",
                "php-test",
                "bash",
                "-lc",
                'mariadb -h mariadb --protocol=tcp --skip-ssl -uroot -proot -e "SELECT 1"',
            ],
        )
        details = extract_output(result).strip()
        if result.returncode == 0 and "1" in details:
            break
        time.sleep(1)

    return {
        "name": "mariadb_from_php_test",
        "status": "passed" if result is not None and result.returncode == 0 and "1" in details else "failed",
        "details": details,
    }


def cmd_suite(args: argparse.Namespace) -> int:
    payload = run_case(
        case_id=args.case_id,
        sut_path=args.sut_path,
        suites=args.suite,
        test_filter=args.test_filter,
        run_label=args.run_label,
        context_label=args.context_label,
        pr_number=args.pr_number,
        pr_head_ref=args.pr_head_ref,
        pr_base_ref=args.pr_base_ref,
    )
    print(json.dumps(payload, indent=2))
    return 0 if payload["status"] == "passed" else 1


def cmd_case(args: argparse.Namespace) -> int:
    payload = run_case(
        case_id=args.case_id,
        sut_path=args.sut_path,
        suites=args.suites,
        test_filter=args.test_filter,
        run_label=args.run_label,
        context_label=args.context_label,
        pr_number=args.pr_number,
        pr_head_ref=args.pr_head_ref,
        pr_base_ref=args.pr_base_ref,
    )
    print(json.dumps(payload, indent=2))
    return 0 if payload["status"] == "passed" else 1


def cmd_quick(args: argparse.Namespace) -> int:
    payload = run_case(
        case_id=args.case_id,
        sut_path=args.sut_path,
        suites="lint,unit,integration",
        run_label=args.run_label,
        context_label=args.context_label,
        pr_number=args.pr_number,
        pr_head_ref=args.pr_head_ref,
        pr_base_ref=args.pr_base_ref,
    )
    print(json.dumps(payload, indent=2))
    return 0 if payload["status"] == "passed" else 1


def cmd_matrix(args: argparse.Namespace) -> int:
    results = []
    exit_code = 0
    for case in load_matrix()["cases"]:
        payload = run_case(
            case["id"],
            args.sut_path,
            run_label=args.run_label,
            context_label=args.context_label,
            pr_number=args.pr_number,
            pr_head_ref=args.pr_head_ref,
            pr_base_ref=args.pr_base_ref,
        )
        results.append(payload)
        if payload["status"] != "passed":
            exit_code = 1
    print(json.dumps({"status": "passed" if exit_code == 0 else "failed", "cases": results}, indent=2))
    return exit_code


def cmd_doctor(args: argparse.Namespace) -> int:
    checks = []
    normalized_sut_path = normalize_sut_path(args.sut_path)
    checks.extend(sut_preflight_checks(normalized_sut_path))
    checks.append(docker_check(normalized_sut_path))
    checks.append(compose_services_check(normalized_sut_path))

    with harness_lock():
        if checks[-1]["status"] == "passed" and checks[-2]["status"] == "passed":
            stack_check = stack_ready_check(normalized_sut_path)
            checks.append(stack_check)
            if stack_check["status"] == "passed":
                checks.append(mariadb_ping_check(normalized_sut_path))
            else:
                checks.append({"name": "mariadb_from_php_test", "status": "skipped", "details": "stack startup failed"})
        else:
            checks.append({"name": "stack_ready", "status": "skipped", "details": "docker or compose checks failed"})
            checks.append({"name": "mariadb_from_php_test", "status": "skipped", "details": "docker or compose checks failed"})

    passed = all(check["status"] == "passed" for check in checks if check["status"] != "skipped")
    payload = {
        "status": "passed" if passed else "failed",
        "sut_path": normalized_sut_path,
        "sut_context": detect_sut_context(
            normalized_sut_path,
            context_label=args.context_label,
            pr_number=args.pr_number,
            pr_head_ref=args.pr_head_ref,
            pr_base_ref=args.pr_base_ref,
        ),
        "checks": checks,
    }
    print(json.dumps(payload, indent=2))
    return 0 if passed else 1


def cmd_report_latest(args: argparse.Namespace) -> int:
    print(json.dumps(report_latest(context_label=args.context_label), indent=2))
    return 0


def cmd_report_case(args: argparse.Namespace) -> int:
    print(json.dumps(report_case(args.case_id, context_label=args.context_label), indent=2))
    return 0


def cmd_logs_case(args: argparse.Namespace) -> int:
    print(json.dumps(logs_case(args.case_id, context_label=args.context_label), indent=2))
    return 0


def cmd_report_html(args: argparse.Namespace) -> int:
    output_path = Path(args.output).expanduser() if args.output else None
    print(json.dumps(write_report_html(output_path), indent=2))
    return 0


def cmd_report_clean(args: argparse.Namespace) -> int:
    payload = clean_reports(
        keep=args.keep,
        case_id=args.case_id,
        delete_all=args.all,
        dry_run=args.dry_run,
        refresh_html=not args.no_html,
    )
    print(json.dumps(payload, indent=2))
    return 0


def cmd_lib_test_catalog_refresh(args: argparse.Namespace) -> int:
    existing_catalog = load_json(LIB_TEST_CATALOG) if LIB_TEST_CATALOG.is_file() else None
    catalog = build_lib_test_catalog(args.sut_path, existing_catalog=existing_catalog)
    write_lib_test_catalog(catalog)
    payload = {
        "status": "passed",
        "catalog_path": str(LIB_TEST_CATALOG),
        "sut_path": normalize_sut_path(args.sut_path),
        "entry_count": len(catalog["entries"]),
        "missing_count": sum(1 for entry in catalog["entries"] if not entry["test_path_exists"]),
    }
    print(json.dumps(payload, indent=2))
    return 0


def cmd_lib_test_missing(args: argparse.Namespace) -> int:
    existing_catalog = load_json(LIB_TEST_CATALOG) if LIB_TEST_CATALOG.is_file() else None
    catalog = build_lib_test_catalog(args.sut_path, existing_catalog=existing_catalog)
    missing = [entry for entry in catalog["entries"] if not entry["test_path_exists"]]
    payload = {
        "status": "passed" if not missing else "failed",
        "sut_path": normalize_sut_path(args.sut_path),
        "catalog_path": str(LIB_TEST_CATALOG),
        "missing_count": len(missing),
        "entries": missing,
    }
    print(json.dumps(payload, indent=2))
    return 0 if not missing else 1


def select_catalog_entry(catalog: dict, lib_file: str) -> dict:
    normalized = normalize_lib_file(lib_file)
    for entry in catalog["entries"]:
        if entry["lib_file"] == normalized:
            return entry
    raise SystemExit(f"Unknown top-level lib file: {normalized}")


def cmd_lib_test_scaffold(args: argparse.Namespace) -> int:
    existing_catalog = load_json(LIB_TEST_CATALOG) if LIB_TEST_CATALOG.is_file() else None
    catalog = build_lib_test_catalog(args.sut_path, existing_catalog=existing_catalog)
    entry = select_catalog_entry(catalog, args.lib_file)
    target = ROOT / entry["test_path"]
    if target.exists() and not args.force:
        raise SystemExit(f"Matching test already exists: {entry['test_path']}")

    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(render_scaffolded_lib_test(entry))

    refreshed_catalog = build_lib_test_catalog(args.sut_path, existing_catalog=catalog)
    write_lib_test_catalog(refreshed_catalog)
    refreshed_entry = select_catalog_entry(refreshed_catalog, entry["lib_file"])
    payload = {
        "status": "passed",
        "lib_file": refreshed_entry["lib_file"],
        "test_path": refreshed_entry["test_path"],
        "test_suite": refreshed_entry["test_suite"],
        "strategy": refreshed_entry["strategy"],
        "load_profile": refreshed_entry["load_profile"],
    }
    print(json.dumps(payload, indent=2))
    return 0


def cmd_lib_test_triage_status(args: argparse.Namespace) -> int:
    existing_catalog = load_json(LIB_TEST_CATALOG) if LIB_TEST_CATALOG.is_file() else None
    catalog = build_lib_test_catalog(args.sut_path, existing_catalog=existing_catalog)
    if args.lib_file:
        entries = [select_catalog_entry(catalog, args.lib_file)]
    elif args.all:
        entries = catalog["entries"]
    else:
        changed = set(changed_top_level_lib_files(args.sut_path))
        entries = [entry for entry in catalog["entries"] if entry["lib_file"] in changed]

    summary: dict[str, int] = {}
    for entry in entries:
        summary[entry["triage_status"]] = summary.get(entry["triage_status"], 0) + 1

    payload = {
        "status": "passed",
        "sut_path": normalize_sut_path(args.sut_path),
        "scope": args.lib_file or ("all" if args.all else "changed"),
        "entry_count": len(entries),
        "triage_summary": summary,
        "entries": entries,
    }
    print(json.dumps(payload, indent=2))
    return 0


def cmd_lib_test_run(args: argparse.Namespace) -> int:
    catalog = load_lib_test_catalog()
    entry = select_catalog_entry(catalog, args.lib_file)
    payload = run_case(
        case_id=args.case_id,
        sut_path=args.sut_path,
        suites=entry["test_suite"],
        test_filter=entry["test_class"],
        run_label=args.run_label,
        context_label=args.context_label,
        pr_number=args.pr_number,
        pr_head_ref=args.pr_head_ref,
        pr_base_ref=args.pr_base_ref,
    )
    result = {
        "lib_file": entry["lib_file"],
        "test_suite": entry["test_suite"],
        "test_class": entry["test_class"],
        "test_path": entry["test_path"],
        "run": payload,
    }
    print(json.dumps(result, indent=2))
    return 0 if payload["status"] == "passed" else 1


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser()
    subparsers = parser.add_subparsers(dest="command", required=True)

    def add_common_case_args(command: argparse.ArgumentParser) -> None:
        command.add_argument("--case-id", default="baseline-default")
        command.add_argument("--sut-path", default=default_sut_path())
        command.add_argument("--run-label")
        command.add_argument("--context-label")
        command.add_argument("--pr-number")
        command.add_argument("--pr-head-ref")
        command.add_argument("--pr-base-ref")

    suite = subparsers.add_parser("suite")
    add_common_case_args(suite)
    suite.add_argument("--suite", required=True, choices=SUITE_CHOICES)
    suite.add_argument("--test-filter")
    suite.set_defaults(func=cmd_suite)

    case = subparsers.add_parser("case")
    add_common_case_args(case)
    case.add_argument("--suites")
    case.add_argument("--test-filter")
    case.set_defaults(func=cmd_case)

    quick = subparsers.add_parser("quick")
    add_common_case_args(quick)
    quick.set_defaults(func=cmd_quick)

    matrix = subparsers.add_parser("matrix")
    matrix.add_argument("--sut-path", default=default_sut_path())
    matrix.add_argument("--run-label")
    matrix.add_argument("--context-label")
    matrix.add_argument("--pr-number")
    matrix.add_argument("--pr-head-ref")
    matrix.add_argument("--pr-base-ref")
    matrix.set_defaults(func=cmd_matrix)

    doctor = subparsers.add_parser("doctor")
    doctor.add_argument("--sut-path", default=default_sut_path())
    doctor.add_argument("--context-label")
    doctor.add_argument("--pr-number")
    doctor.add_argument("--pr-head-ref")
    doctor.add_argument("--pr-base-ref")
    doctor.set_defaults(func=cmd_doctor)

    report_latest_parser = subparsers.add_parser("report-latest")
    report_latest_parser.add_argument("--context-label")
    report_latest_parser.set_defaults(func=cmd_report_latest)

    report_case_parser = subparsers.add_parser("report-case")
    report_case_parser.add_argument("--case-id", required=True)
    report_case_parser.add_argument("--context-label")
    report_case_parser.set_defaults(func=cmd_report_case)

    logs_case_parser = subparsers.add_parser("logs-case")
    logs_case_parser.add_argument("--case-id", required=True)
    logs_case_parser.add_argument("--context-label")
    logs_case_parser.set_defaults(func=cmd_logs_case)

    report_html_parser = subparsers.add_parser("report-html")
    report_html_parser.add_argument("--output")
    report_html_parser.set_defaults(func=cmd_report_html)

    report_clean_parser = subparsers.add_parser("report-clean")
    report_clean_parser.add_argument("--keep", type=int, default=20)
    report_clean_parser.add_argument("--case-id")
    report_clean_parser.add_argument("--all", action="store_true")
    report_clean_parser.add_argument("--dry-run", action="store_true")
    report_clean_parser.add_argument("--no-html", action="store_true")
    report_clean_parser.set_defaults(func=cmd_report_clean)

    lib_catalog_refresh = subparsers.add_parser("lib-test-catalog-refresh")
    lib_catalog_refresh.add_argument("--sut-path", default=default_sut_path())
    lib_catalog_refresh.set_defaults(func=cmd_lib_test_catalog_refresh)

    lib_missing = subparsers.add_parser("lib-test-missing")
    lib_missing.add_argument("--sut-path", default=default_sut_path())
    lib_missing.set_defaults(func=cmd_lib_test_missing)

    lib_scaffold = subparsers.add_parser("lib-test-scaffold")
    lib_scaffold.add_argument("--sut-path", default=default_sut_path())
    lib_scaffold.add_argument("--lib-file", required=True)
    lib_scaffold.add_argument("--force", action="store_true")
    lib_scaffold.set_defaults(func=cmd_lib_test_scaffold)

    lib_triage = subparsers.add_parser("lib-test-triage-status")
    lib_triage.add_argument("--sut-path", default=default_sut_path())
    lib_triage.add_argument("--lib-file")
    lib_triage.add_argument("--all", action="store_true")
    lib_triage.set_defaults(func=cmd_lib_test_triage_status)

    lib_run = subparsers.add_parser("lib-test-run")
    add_common_case_args(lib_run)
    lib_run.add_argument("--lib-file", required=True)
    lib_run.set_defaults(func=cmd_lib_test_run)

    return parser


def main() -> int:
    parser = build_parser()
    args = parser.parse_args()
    return args.func(args)


if __name__ == "__main__":
    sys.exit(main())
