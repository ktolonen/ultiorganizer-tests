#!/usr/bin/env python3

from __future__ import annotations

import argparse
import json
import os
import re
import shutil
import subprocess
import sys
import time
import xml.etree.ElementTree as ET
from datetime import datetime, timezone
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
WORKSPACE = Path("/workspace")
SUT_SOURCE = Path(os.environ.get("UO_SUT_CONTAINER_PATH", "/sut-ro"))
RUNTIME_ROOT = WORKSPACE / ".runtime"
WEBROOT_LINK = RUNTIME_ROOT / "webroot"
REPORTS_ROOT = WORKSPACE / "reports"
MATRIX_CONFIG = WORKSPACE / "config" / "matrix.json"
PROFILE_DIR = WORKSPACE / "config" / "profiles"
FIXTURE_DIR = WORKSPACE / "fixtures"
APACHE_ERROR_LOG = Path("/var/log/apache2/error.log")


class RunnerFailure(RuntimeError):
    def __init__(self, classification: str, reason: str, details: dict | None = None):
        super().__init__(reason)
        self.classification = classification
        self.reason = reason
        self.details = details or {}


def load_matrix() -> dict:
    return json.loads(MATRIX_CONFIG.read_text())


def get_case(case_id: str) -> dict:
    for case in load_matrix()["cases"]:
        if case["id"] == case_id:
            return case
    raise SystemExit(f"Unknown case id: {case_id}")


def get_profile(profile_id: str) -> dict:
    profile_path = PROFILE_DIR / f"{profile_id}.json"
    return json.loads(profile_path.read_text())


def run(cmd: list[str], *, cwd: Path | None = None, env: dict[str, str] | None = None, stdout_path: Path | None = None) -> subprocess.CompletedProcess[str]:
    run_env = os.environ.copy()
    if env:
        run_env.update(env)

    if stdout_path is None:
        return subprocess.run(cmd, cwd=cwd, env=run_env, text=True, capture_output=True, check=False)

    stdout_path.parent.mkdir(parents=True, exist_ok=True)
    with stdout_path.open("w", encoding="utf-8") as handle:
        process = subprocess.run(cmd, cwd=cwd, env=run_env, text=True, stdout=handle, stderr=subprocess.STDOUT, check=False)
    text = stdout_path.read_text(encoding="utf-8")
    process.stdout = text
    process.stderr = ""
    return process


def sanitize(value: str) -> str:
    return re.sub(r"[^a-zA-Z0-9._-]+", "-", value).strip("-") or "run"


def write_json(path: Path, payload: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def append_log(path: Path, heading: str, content: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("a", encoding="utf-8") as handle:
        handle.write(f"[{heading}]\n")
        handle.write(content.rstrip() + "\n\n")


def command_output(result: subprocess.CompletedProcess[str]) -> str:
    return ((result.stdout or "") + (result.stderr or "")).strip()


def ensure_vendor() -> dict:
    vendor_autoload = WORKSPACE / "vendor" / "autoload.php"
    if vendor_autoload.is_file():
        return {"status": "cached", "vendor_autoload": str(vendor_autoload)}

    env = {
        "COMPOSER_HOME": "/tmp/composer",
        "COMPOSER_CACHE_DIR": "/tmp/composer-cache",
    }
    result = run(["composer", "install", "--no-interaction", "--prefer-dist"], cwd=WORKSPACE, env=env)
    if result.returncode != 0:
        raise SystemExit(result.stdout)
    return {"status": "installed", "vendor_autoload": str(vendor_autoload)}


def write_test_config(case: dict, profile: dict, runtime_sut: Path) -> Path:
    db_name = case["database_name"]
    if not db_name.startswith("ultiorganizer_test"):
        raise RunnerFailure(
            "runtime_sut_copy_config_failure",
            f"Refusing to write non-test database config for {db_name}",
        )

    config_path = runtime_sut / "conf" / "config.inc.php"
    config_path.parent.mkdir(parents=True, exist_ok=True)
    lines = [
        "<?php",
        f"define('DB_HOST', '{os.environ.get('UO_DB_HOST', 'mariadb')}');",
        f"define('DB_USER', '{os.environ.get('UO_DB_USER', 'ultiorganizer')}');",
        f"define('DB_PASSWORD', '{os.environ.get('UO_DB_PASSWORD', 'ultiorganizer')}');",
        f"define('DB_DATABASE', '{db_name}');",
        f"define('BASEURL', '{profile['base_url']}');",
        "define('UPLOAD_DIR', 'images/uploads/');",
        f"define('CUSTOMIZATIONS', '{case['customization']}');",
        "define('DATE_FORMAT', _('%d.%m.%Y %H:%M'));",
        "define('WORD_DELIMITER', '/([\\;\\,\\-_\\s\\/\\.])/');",
        f"define('ENABLE_ADMIN_DB_ACCESS', '{profile['enable_admin_db_access']}');",
        f"define('DISABLE_SELF_REGISTRATION', {'true' if profile['disable_self_registration'] else 'false'});",
        f"define('ALLOW_INSTALL', {'true' if profile['allow_install'] else 'false'});",
        f"define('ANONYMOUS_RESULT_INPUT', {'true' if profile['anonymous_result_input'] else 'false'});",
        f"define('API_RATE_LIMIT', {int(profile['api_rate_limit'])});",
        f"define('API_RATE_WINDOW', {int(profile['api_rate_window'])});",
        "$locales = array(",
        "  'en_GB.utf8' => 'English',",
        "  'fi_FI.utf8' => 'Suomi'",
        ");",
        "?>",
    ]
    config_path.write_text("\n".join(lines) + "\n", encoding="utf-8")
    return config_path


def prepare_runtime(case: dict, profile: dict, setup_log_path: Path) -> dict:
    runtime_case_root = RUNTIME_ROOT / "cases" / case["id"]
    runtime_sut = runtime_case_root / "sut"
    runtime_case_root.mkdir(parents=True, exist_ok=True)
    runtime_sut.mkdir(parents=True, exist_ok=True)

    rsync_cmd = [
        "rsync",
        "-a",
        "--delete",
        "--exclude",
        ".git",
        f"{SUT_SOURCE}/",
        f"{runtime_sut}/",
    ]
    result = run(rsync_cmd)
    append_log(setup_log_path, "prepare_runtime_rsync", command_output(result) or "rsync completed")
    if result.returncode != 0:
        raise RunnerFailure(
            "runtime_sut_copy_config_failure",
            "Copying the SUT into the runtime workspace failed",
            {"output": command_output(result)},
        )

    try:
        config_path = write_test_config(case, profile, runtime_sut)
    except OSError as exc:
        raise RunnerFailure(
            "runtime_sut_copy_config_failure",
            "Generating the test config file failed",
            {"error": str(exc)},
        ) from exc

    WEBROOT_LINK.parent.mkdir(parents=True, exist_ok=True)
    if WEBROOT_LINK.is_symlink() or WEBROOT_LINK.exists():
        if WEBROOT_LINK.is_dir() and not WEBROOT_LINK.is_symlink():
            shutil.rmtree(WEBROOT_LINK)
        else:
            WEBROOT_LINK.unlink()
    WEBROOT_LINK.symlink_to(runtime_sut, target_is_directory=True)
    append_log(setup_log_path, "prepare_runtime_webroot", f"webroot -> {runtime_sut}")

    return {
        "runtime_case_root": str(runtime_case_root),
        "runtime_sut": str(runtime_sut),
        "config_path": str(config_path),
        "webroot": str(WEBROOT_LINK),
    }


def mariadb_base_command() -> list[str]:
    root_password = os.environ.get("MARIADB_ROOT_PASSWORD", "root")
    return [
        "mariadb",
        "-h",
        os.environ.get("UO_DB_HOST", "mariadb"),
        "--protocol=tcp",
        "--skip-ssl",
        "-uroot",
        f"-p{root_password}",
    ]


def wait_for_database(setup_log_path: Path) -> None:
    root_password = os.environ.get("MARIADB_ROOT_PASSWORD", "root")
    for _ in range(30):
        ping = run(
            [
                "mariadb-admin",
                "-h",
                os.environ.get("UO_DB_HOST", "mariadb"),
                "--protocol=tcp",
                "--skip-ssl",
                "-uroot",
                f"-p{root_password}",
                "ping",
                "--silent",
            ]
        )
        if ping.returncode == 0:
            append_log(setup_log_path, "database_ping", "MariaDB responded to ping")
            return
        time.sleep(1)
    raise RunnerFailure("database_initialization_failure", "MariaDB did not become ready in time")


def initialize_database(case: dict, setup_log_path: Path) -> None:
    db_name = case["database_name"]
    if not db_name.startswith("ultiorganizer_test"):
        raise RunnerFailure(
            "database_initialization_failure",
            f"Refusing to initialize non-test database: {db_name}",
        )

    wait_for_database(setup_log_path)
    base_cmd = mariadb_base_command()

    sql = (
        f"DROP DATABASE IF EXISTS `{db_name}`; "
        f"CREATE DATABASE `{db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; "
        f"GRANT ALL PRIVILEGES ON `{db_name}`.* TO 'ultiorganizer'@'%'; "
        "FLUSH PRIVILEGES;"
    )
    result = run(base_cmd + ["-e", sql])
    append_log(setup_log_path, "database_create", command_output(result) or f"Initialized {db_name}")
    if result.returncode != 0:
        raise RunnerFailure(
            "database_initialization_failure",
            "Creating the disposable test database failed",
            {"output": command_output(result)},
        )

    schema_path = SUT_SOURCE / "sql" / "ultiorganizer.sql"
    schema_result = run(base_cmd + [db_name, "-e", f"source {schema_path}"])
    append_log(setup_log_path, "database_schema", command_output(schema_result) or f"Loaded schema {schema_path}")
    if schema_result.returncode != 0:
        raise RunnerFailure(
            "database_initialization_failure",
            "Loading the production schema failed",
            {"output": command_output(schema_result), "schema_path": str(schema_path)},
        )

    fixture_path = FIXTURE_DIR / f"{case['fixture_pack']}.sql"
    fixture_result = run(base_cmd + [db_name, "-e", f"source {fixture_path}"])
    append_log(setup_log_path, "database_fixture", command_output(fixture_result) or f"Loaded fixture {fixture_path}")
    if fixture_result.returncode != 0:
        raise RunnerFailure(
            "fixture_load_failure",
            "Loading the harness fixture pack failed",
            {"output": command_output(fixture_result), "fixture_path": str(fixture_path)},
        )


def junit_summary(junit_path: Path) -> dict:
    if not junit_path.is_file():
        return {
            "tests": 0,
            "failures": 0,
            "errors": 0,
            "skipped": 0,
            "time": 0.0,
            "failed_tests": [],
        }

    root = ET.parse(junit_path).getroot()
    test_nodes = list(root) if root.tag == "testsuites" else [root]
    summary = {
        "tests": 0,
        "failures": 0,
        "errors": 0,
        "skipped": 0,
        "time": 0.0,
        "failed_tests": [],
    }

    for node in test_nodes:
        summary["tests"] += int(node.attrib.get("tests", 0))
        summary["failures"] += int(node.attrib.get("failures", 0))
        summary["errors"] += int(node.attrib.get("errors", 0))
        summary["skipped"] += int(node.attrib.get("skipped", 0))
        summary["time"] += float(node.attrib.get("time", 0.0))
        for testcase in node.iter("testcase"):
            failures = list(testcase.findall("failure")) + list(testcase.findall("error"))
            if not failures:
                continue
            failure_node = failures[0]
            detail_text = (failure_node.text or "").strip()
            message = failure_node.attrib.get("message", "").strip()
            merged_message = "\n".join(part for part in [message, detail_text] if part).strip()
            summary["failed_tests"].append(
                {
                    "name": testcase.attrib.get("name", ""),
                    "class": testcase.attrib.get("class", ""),
                    "message": merged_message,
                }
            )

    return summary


def log_excerpt(log_path: Path, lines: int = 40) -> str:
    if not log_path.is_file():
        return ""
    content = log_path.read_text(encoding="utf-8", errors="replace").splitlines()
    return "\n".join(content[-lines:])


def parse_smoke_failure(message: str) -> dict | None:
    prefix = "SMOKE_FAILURE:"
    if not message.startswith(prefix):
        return None
    payload = message[len(prefix) :].strip()
    try:
        return json.loads(payload)
    except json.JSONDecodeError:
        return {"raw": payload}


def first_failed_test(failed_tests: list[dict]) -> dict | None:
    return failed_tests[0] if failed_tests else None


def extract_smoke_failures(failed_tests: list[dict]) -> list[dict]:
    failed_pages = []
    for failed in failed_tests:
        parsed = parse_smoke_failure(failed.get("message", ""))
        if parsed:
            failed_pages.append(parsed)
    return failed_pages


def suite_failure_classification(suite: str, status: str) -> str | None:
    if status == "passed":
        return None
    if suite == "smoke":
        return "smoke_http_runtime_failure"
    return "phpunit_test_failure"


def suite_failure_reason(suite: str, failed_tests: list[dict], failed_pages: list[dict]) -> str | None:
    if suite == "smoke" and failed_pages:
        page = failed_pages[0]
        return f"Smoke page {page.get('page_id', 'unknown')} failed"
    first = first_failed_test(failed_tests)
    if first:
        return f"{first.get('class', '')}::{first.get('name', '')}".strip(":")
    return None


def run_suite(case: dict, suite: str, run_root: Path, test_filter: str | None, run_label: str | None) -> dict:
    runtime_sut = RUNTIME_ROOT / "cases" / case["id"] / "sut"
    junit_path = run_root / "junit" / f"{suite}.xml"
    log_path = run_root / "logs" / f"{suite}.log"
    env = {
        "UO_SUT_ROOT": str(runtime_sut),
        "UO_BASE_URL": "http://127.0.0.1",
        "UO_APACHE_ERROR_LOG": str(APACHE_ERROR_LOG),
        "UO_RUN_LABEL": run_label or "",
        "UO_SMOKE_PAGES": json.dumps(case.get("smoke_pages", [])),
    }

    cmd = [
        "php",
        "vendor/bin/phpunit",
        "--configuration",
        "phpunit.xml.dist",
        "--testsuite",
        suite.capitalize(),
        "--log-junit",
        str(junit_path),
    ]
    if test_filter:
        cmd.extend(["--filter", test_filter])

    started = datetime.now(timezone.utc)
    started_monotonic = time.monotonic()
    result = run(cmd, cwd=WORKSPACE, env=env, stdout_path=log_path)
    finished = datetime.now(timezone.utc)
    finished_monotonic = time.monotonic()
    parsed = junit_summary(junit_path)
    status = "passed" if result.returncode == 0 else "failed"
    if result.returncode not in (0, 1):
        status = "error"

    failed_pages = extract_smoke_failures(parsed["failed_tests"])
    failure_classification = suite_failure_classification(suite, status)
    failure_reason = suite_failure_reason(suite, parsed["failed_tests"], failed_pages)

    return {
        "suite": suite,
        "status": status,
        "started_at": started.isoformat(),
        "finished_at": finished.isoformat(),
        "duration_seconds": round(max(0.0, finished_monotonic - started_monotonic), 3),
        "exit_code": result.returncode,
        "junit_path": str(junit_path),
        "log_path": str(log_path),
        "tests": parsed["tests"],
        "failures": parsed["failures"],
        "errors": parsed["errors"],
        "skipped": parsed["skipped"],
        "failed_tests": parsed["failed_tests"],
        "first_failed_test": first_failed_test(parsed["failed_tests"]),
        "failed_pages": failed_pages,
        "failure_classification": failure_classification,
        "failure_reason": failure_reason,
        "log_excerpt": log_excerpt(log_path) if status != "passed" else "",
    }


def case_run_root(case_id: str, run_label: str | None) -> Path:
    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    label = sanitize(run_label) if run_label else stamp
    return REPORTS_ROOT / "cases" / case_id / label


def detect_overall_failure(setup_result: dict, suite_results: list[dict]) -> tuple[str | None, str | None, dict | None]:
    if setup_result["status"] != "passed":
        return (
            setup_result.get("failure_classification"),
            setup_result.get("failure_reason"),
            None,
        )
    for suite in suite_results:
        if suite["status"] != "passed":
            return (
                suite.get("failure_classification"),
                suite.get("failure_reason"),
                suite,
            )
    return (None, None, None)


def git_value(args: list[str]) -> str:
    result = subprocess.run(args, text=True, capture_output=True, check=False)
    return result.stdout.strip()


def write_markdown(path: Path, summary: dict) -> None:
    lines = [
        f"# Test Summary: {summary['case_id']}",
        "",
        f"- Status: `{summary['status']}`",
        f"- SUT path: `{summary['sut_source']}`",
        f"- Customization: `{summary['customization']}`",
        f"- Config profile: `{summary['config_profile']}`",
        f"- Started: `{summary['started_at']}`",
        f"- Finished: `{summary['finished_at']}`",
        f"- Run label: `{summary['run_label']}`",
    ]
    if summary.get("failure_classification"):
        lines.append(f"- Failure class: `{summary['failure_classification']}`")
    if summary.get("failure_reason"):
        lines.append(f"- Failure reason: `{summary['failure_reason']}`")

    lines.extend(
        [
            "",
            "## Setup",
            "",
            f"- status: `{summary['setup_result']['status']}`",
            f"- log: `{summary['setup_result']['log_path']}`",
        ]
    )
    if summary["setup_result"].get("failure_classification"):
        lines.append(f"- failure class: `{summary['setup_result']['failure_classification']}`")
    if summary["setup_result"].get("failure_reason"):
        lines.append(f"- failure reason: `{summary['setup_result']['failure_reason']}`")

    lines.extend(["", "## Suites", ""])
    if not summary["suite_results"]:
        lines.append("- No suites executed")
    for suite in summary["suite_results"]:
        lines.append(
            f"- `{suite['suite']}`: `{suite['status']}` "
            f"(tests={suite['tests']}, failures={suite['failures']}, errors={suite['errors']}, skipped={suite['skipped']})"
        )
        lines.append(f"  junit: `{suite['junit_path']}`")
        lines.append(f"  log: `{suite['log_path']}`")
        if suite.get("failure_classification"):
            lines.append(f"  failure class: `{suite['failure_classification']}`")
        if suite.get("first_failed_test"):
            failed = suite["first_failed_test"]
            lines.append(f"  first failed test: `{failed['class']}::{failed['name']}`")
        for page in suite.get("failed_pages", []):
            lines.append(
                f"  failed page: `{page.get('page_id', 'unknown')}` status={page.get('status_code', 'n/a')}"
            )
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def update_latest_pointers(case_id: str, summary: dict) -> None:
    write_json(REPORTS_ROOT / "summary" / "latest.json", summary)
    write_markdown(REPORTS_ROOT / "summary" / f"{case_id}-latest.md", summary)
    write_json(REPORTS_ROOT / "cases" / case_id / "latest.json", summary)
    if summary["status"] == "failed":
        write_json(REPORTS_ROOT / "summary" / "latest-failed.json", summary)
        write_json(REPORTS_ROOT / "cases" / case_id / "latest-failed.json", summary)


def finalize_summary(
    case: dict,
    profile: dict,
    requested_suites: list[str],
    suite_results: list[dict],
    run_root: Path,
    run_label: str | None,
    setup_result: dict,
) -> dict:
    started = setup_result["started_at"]
    finished = setup_result["finished_at"]
    if suite_results:
        started = min([started] + [result["started_at"] for result in suite_results])
        finished = max([finished] + [result["finished_at"] for result in suite_results])

    failure_classification, failure_reason, failing_suite = detect_overall_failure(setup_result, suite_results)
    overall = "passed" if failure_classification is None else "failed"

    summary = {
        "status": overall,
        "case_id": case["id"],
        "customization": case["customization"],
        "config_profile": case["config_profile"],
        "fixture_pack": case["fixture_pack"],
        "suites": case["suites"],
        "suites_run": requested_suites,
        "smoke_pages": case.get("smoke_pages", []),
        "suite_results": suite_results,
        "setup_result": setup_result,
        "started_at": started,
        "finished_at": finished,
        "sut_source": str(SUT_SOURCE),
        "run_label": run_label or "",
        "branch": git_value(["git", "-C", str(SUT_SOURCE), "rev-parse", "--abbrev-ref", "HEAD"]),
        "commit_sha": git_value(["git", "-C", str(SUT_SOURCE), "rev-parse", "HEAD"]),
        "failure_classification": failure_classification,
        "failure_reason": failure_reason,
        "first_failed_test": failing_suite.get("first_failed_test") if failing_suite else None,
        "failed_pages": failing_suite.get("failed_pages", []) if failing_suite else [],
        "artifact_paths": {
            "case_root": str(run_root),
            "summary_json": str(run_root / "summary" / "summary.json"),
            "summary_md": str(run_root / "summary" / "summary.md"),
            "setup_log": setup_result["log_path"],
            "latest_json": str(REPORTS_ROOT / "summary" / "latest.json"),
            "latest_failed_json": str(REPORTS_ROOT / "summary" / "latest-failed.json"),
            "case_latest_json": str(REPORTS_ROOT / "cases" / case["id"] / "latest.json"),
            "case_latest_failed_json": str(REPORTS_ROOT / "cases" / case["id"] / "latest-failed.json"),
        },
    }
    write_json(run_root / "summary" / "summary.json", summary)
    write_markdown(run_root / "summary" / "summary.md", summary)
    update_latest_pointers(case["id"], summary)
    return summary


def cmd_ensure_deps(_: argparse.Namespace) -> int:
    payload = ensure_vendor()
    print(json.dumps(payload))
    return 0


def cmd_prepare_case(args: argparse.Namespace) -> int:
    case = get_case(args.case_id)
    profile = get_profile(case["config_profile"])
    setup_log_path = REPORTS_ROOT / "cases" / case["id"] / "prepare-case.log"
    runtime_info = prepare_runtime(case, profile, setup_log_path)
    initialize_database(case, setup_log_path)
    payload = {
        "status": "prepared",
        "case_id": case["id"],
        "runtime": runtime_info,
        "setup_log": str(setup_log_path),
    }
    print(json.dumps(payload))
    return 0


def cmd_run_case(args: argparse.Namespace) -> int:
    case = get_case(args.case_id)
    profile = get_profile(case["config_profile"])
    requested_suites = [suite.strip() for suite in (args.suites or ",".join(case["suites"])).split(",") if suite.strip()]
    run_root = case_run_root(case["id"], args.run_label)
    (run_root / "junit").mkdir(parents=True, exist_ok=True)
    (run_root / "logs").mkdir(parents=True, exist_ok=True)
    (run_root / "summary").mkdir(parents=True, exist_ok=True)
    setup_log_path = run_root / "logs" / "setup.log"

    setup_started = datetime.now(timezone.utc)
    setup_started_monotonic = time.monotonic()
    runtime_info = None
    try:
        runtime_info = prepare_runtime(case, profile, setup_log_path)
        initialize_database(case, setup_log_path)
        setup_status = "passed"
        setup_classification = None
        setup_reason = None
    except RunnerFailure as exc:
        append_log(setup_log_path, "setup_failure", exc.reason)
        setup_status = "failed"
        setup_classification = exc.classification
        setup_reason = exc.reason
    setup_finished = datetime.now(timezone.utc)
    setup_finished_monotonic = time.monotonic()

    setup_result = {
        "status": setup_status,
        "started_at": setup_started.isoformat(),
        "finished_at": setup_finished.isoformat(),
        "duration_seconds": round(max(0.0, setup_finished_monotonic - setup_started_monotonic), 3),
        "log_path": str(setup_log_path),
        "failure_classification": setup_classification,
        "failure_reason": setup_reason,
        "runtime": runtime_info,
    }

    suite_results: list[dict] = []
    if setup_status == "passed":
        for suite in requested_suites:
            suite_results.append(run_suite(case, suite, run_root, args.test_filter, args.run_label))

    summary = finalize_summary(case, profile, requested_suites, suite_results, run_root, args.run_label, setup_result)
    print(json.dumps(summary))
    return 0 if summary["status"] == "passed" else 1


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser()
    subparsers = parser.add_subparsers(dest="command", required=True)

    ensure_deps = subparsers.add_parser("ensure-deps")
    ensure_deps.set_defaults(func=cmd_ensure_deps)

    prepare_case = subparsers.add_parser("prepare-case")
    prepare_case.add_argument("--case-id", required=True)
    prepare_case.set_defaults(func=cmd_prepare_case)

    run_case_parser = subparsers.add_parser("run-case")
    run_case_parser.add_argument("--case-id", required=True)
    run_case_parser.add_argument("--suites")
    run_case_parser.add_argument("--test-filter")
    run_case_parser.add_argument("--run-label")
    run_case_parser.set_defaults(func=cmd_run_case)

    return parser


def main() -> int:
    parser = build_parser()
    args = parser.parse_args()
    return args.func(args)


if __name__ == "__main__":
    sys.exit(main())
