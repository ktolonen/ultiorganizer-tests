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
import urllib.error
import urllib.parse
import urllib.request
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
PHPUNIT_SUITES = {"unit", "integration", "smoke"}
CrawlFailure = dict[str, object]


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


def load_sut_context() -> dict:
    raw = os.environ.get("UO_SUT_CONTEXT_JSON", "").strip()
    if not raw:
        return {}
    try:
        value = json.loads(raw)
    except json.JSONDecodeError:
        return {}
    return value if isinstance(value, dict) else {}


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


def write_test_config(case: dict, profile: dict, runtime_sut: Path, maintenance_runtime_dir: Path) -> Path:
    db_name = case["database_name"]
    if not db_name.startswith("ultiorganizer_test"):
        raise RunnerFailure(
            "runtime_sut_copy_config_failure",
            f"Refusing to write non-test database config for {db_name}",
        )

    config_path = runtime_sut / "conf" / "config.inc.php"
    config_path.parent.mkdir(parents=True, exist_ok=True)
    if config_path.exists():
        config_path.chmod(0o644)
    maintenance_runtime_dir.mkdir(parents=True, exist_ok=True)
    maintenance_runtime_dir.chmod(0o777)
    lines = [
        "<?php",
        f"define('DB_HOST', '{os.environ.get('UO_DB_HOST', 'mariadb')}');",
        f"define('DB_USER', '{os.environ.get('UO_DB_USER', 'ultiorganizer')}');",
        f"define('DB_PASSWORD', '{os.environ.get('UO_DB_PASSWORD', 'ultiorganizer')}');",
        f"define('DB_DATABASE', '{db_name}');",
        f"define('BASEURL', '{profile['base_url']}');",
        "define('UPLOAD_DIR', 'images/uploads/');",
        f"define('MAINTENANCE_RUNTIME_DIR', '{maintenance_runtime_dir}');",
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
    maintenance_runtime_dir = runtime_case_root / "maintenance-runtime"
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
        config_path = write_test_config(case, profile, runtime_sut, maintenance_runtime_dir)
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
        "maintenance_runtime_dir": str(maintenance_runtime_dir),
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
    empty_summary = {
        "tests": 0,
        "failures": 0,
        "errors": 0,
        "skipped": 0,
        "time": 0.0,
        "failed_tests": [],
    }

    if not junit_path.is_file():
        return empty_summary

    if junit_path.stat().st_size == 0:
        return {
            **empty_summary,
            "parse_error": "JUnit XML file was empty",
        }

    try:
        root = ET.parse(junit_path).getroot()
    except ET.ParseError as exc:
        return {
            **empty_summary,
            "parse_error": str(exc),
        }
    test_nodes = list(root) if root.tag == "testsuites" else [root]
    summary = dict(empty_summary)

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


def parse_failed_block(log_path: Path, heading: str) -> list[str]:
    if not log_path.is_file():
        return []

    lines = log_path.read_text(encoding="utf-8", errors="replace").splitlines()
    failures: list[str] = []
    capture = False
    for line in lines:
        if line.strip() == heading:
            capture = True
            continue
        if not capture:
            continue
        if line.startswith(" - "):
            failures.append(line[3:].strip())
            continue
        if line.startswith("[") or not line.strip():
            break
    return failures


def count_manifest_entries(path: Path) -> int:
    if not path.is_file():
        return 0
    return sum(1 for line in path.read_text(encoding="utf-8", errors="replace").splitlines() if line.strip())


def crawl_plan_env(plan: dict) -> dict[str, str]:
    env: dict[str, str] = {}
    scalar_mappings = {
        "accept_regex": "WGET_ACCEPT_REGEX",
        "reject_regex": "WGET_REJECT_REGEX",
        "auth_failure_regex": "WGET_AUTH_FAILURE_REGEX",
        "login_url": "WGET_LOGIN_URL",
        "verify_url": "WGET_VERIFY_URL",
        "page_delay": "WGET_PAGE_DELAY",
        "retries": "WGET_RETRIES",
        "timeout": "WGET_TIMEOUT",
        "max_depth": "WGET_CRAWL_MAX_DEPTH",
        "max_pages": "WGET_CRAWL_MAX_PAGES",
        "max_pages_per_view": "WGET_CRAWL_MAX_PAGES_PER_VIEW",
        "max_visits_per_url": "WGET_MAX_VISITS_PER_URL",
    }
    for key, env_name in scalar_mappings.items():
        value = plan.get(key)
        if value is None:
            continue
        env[env_name] = str(value)

    if "block_auth_routes" in plan:
        env["WGET_BLOCK_AUTH_ROUTES"] = "1" if plan["block_auth_routes"] else "0"

    auth_user = plan.get("auth_user")
    auth_pass = plan.get("auth_pass")
    if isinstance(auth_user, str) and auth_user:
        env["WGET_LOGIN_USER"] = auth_user
    if isinstance(auth_pass, str) and auth_pass:
        env["WGET_LOGIN_PASS"] = auth_pass

    auth_user_env = plan.get("auth_user_env")
    auth_pass_env = plan.get("auth_pass_env")
    if isinstance(auth_user_env, str) and auth_user_env:
        value = os.environ.get(auth_user_env, "")
        if value:
            env["WGET_LOGIN_USER"] = value
    if isinstance(auth_pass_env, str) and auth_pass_env:
        value = os.environ.get(auth_pass_env, "")
        if value:
            env["WGET_LOGIN_PASS"] = value

    return env


def run_follow_links_plan(plan: dict, plan_dir: Path) -> tuple[subprocess.CompletedProcess[str], dict]:
    start_url_or_path = str(plan.get("start_url_or_path", "")).strip()
    if not start_url_or_path:
        raise RunnerFailure("crawl_runtime_failure", f"Crawl plan {plan.get('id', 'unknown')} is missing start_url_or_path")

    env = crawl_plan_env(plan)
    result = run(
        [
            "bash",
            str(WORKSPACE / "wget_follow_links.sh"),
            "http://127.0.0.1",
            start_url_or_path,
            str(plan_dir),
        ],
        cwd=WORKSPACE,
        env=env,
    )
    manifest_path = plan_dir / "manifest.tsv"
    details = {
        "type": "follow_links",
        "start_url_or_path": start_url_or_path,
        "manifest_path": str(manifest_path),
        "downloaded_pages": count_manifest_entries(manifest_path),
        "failed_urls": parse_failed_block(plan_dir / "wget_follow_links.log", "Failed URLs:"),
    }
    return result, details


def run_php_files_plan(case: dict, plan: dict, runtime_sut: Path, plan_dir: Path) -> tuple[subprocess.CompletedProcess[str], dict]:
    input_root_value = str(plan.get("input_root", ".")).strip() or "."
    input_root = (runtime_sut / input_root_value).resolve()
    try:
        input_root.relative_to(runtime_sut.resolve())
    except ValueError as exc:
        raise RunnerFailure(
            "crawl_runtime_failure",
            f"Crawl plan {plan.get('id', 'unknown')} input_root escapes the runtime SUT: {input_root_value}",
        ) from exc

    env = crawl_plan_env(plan)
    base_url = str(plan.get("base_url", "http://127.0.0.1")).strip() or "http://127.0.0.1"
    result = run(
        [
            "bash",
            str(WORKSPACE / "wget_php_files.sh"),
            base_url,
            str(input_root),
            str(plan_dir),
        ],
        cwd=WORKSPACE,
        env=env,
    )
    log_path = plan_dir / "wget_php_files.log"
    downloaded_files = []
    if plan_dir.is_dir():
        downloaded_files = [
            str(path.relative_to(plan_dir))
            for path in plan_dir.rglob("*.php")
            if path.is_file()
        ]
    details = {
        "type": "php_files",
        "base_url": base_url,
        "input_root": str(input_root),
        "downloaded_files": len(downloaded_files),
        "failed_files": parse_failed_block(log_path, "Failed downloads:"),
    }
    return result, details


class NoRedirectHandler(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def http_probe(url: str, timeout: int = 20) -> dict:
    request = urllib.request.Request(url, headers={"User-Agent": "ultiorganizer-harness-path-probe/1.0"})
    opener = urllib.request.build_opener(NoRedirectHandler)
    try:
        with opener.open(request, timeout=timeout) as response:
            body = response.read().decode("utf-8", errors="replace")
            return {
                "status_code": response.getcode(),
                "headers": dict(response.headers.items()),
                "body": body,
                "final_url": response.geturl(),
            }
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", errors="replace")
        return {
            "status_code": exc.code,
            "headers": dict(exc.headers.items()),
            "body": body,
            "final_url": exc.geturl(),
        }


def run_path_probes_plan(plan: dict, plan_dir: Path) -> tuple[subprocess.CompletedProcess[str], dict]:
    probes = plan.get("probes", [])
    if not isinstance(probes, list) or not probes:
        raise RunnerFailure("crawl_runtime_failure", f"Crawl plan {plan.get('id', 'unknown')} defines no probes")

    base_url = str(plan.get("base_url", "http://127.0.0.1")).rstrip("/") or "http://127.0.0.1"
    forbidden_patterns = [str(pattern) for pattern in plan.get("forbidden_body_regexes", []) if str(pattern).strip()]
    timeout = int(plan.get("timeout", 20))
    log_path = plan_dir / "path_probes.log"
    results: list[dict] = []
    failures: list[dict] = []

    with log_path.open("w", encoding="utf-8") as log_handle:
        for probe in probes:
            probe_id = sanitize(str(probe.get("id", "")).strip() or "probe")
            path = str(probe.get("path", "")).strip()
            if not path:
                failures.append({"id": probe_id, "reason": "missing path"})
                log_handle.write(f"[FAIL] {probe_id}: missing path\n")
                continue

            url = path if "://" in path else f"{base_url}{path}"
            expected_statuses = [int(status) for status in probe.get("expected_statuses", [200])]
            response = http_probe(url, timeout=timeout)
            body = response["body"]
            status_code = int(response["status_code"])
            matched_forbidden = [
                pattern for pattern in forbidden_patterns if re.search(pattern, body, flags=re.IGNORECASE)
            ]
            location = response["headers"].get("Location", "")

            probe_result = {
                "id": probe_id,
                "url": url,
                "status_code": status_code,
                "expected_statuses": expected_statuses,
                "location": location,
                "matched_forbidden_patterns": matched_forbidden,
                "body_snippet": re.sub(r"\s+", " ", body).strip()[:300],
            }
            results.append(probe_result)

            if status_code not in expected_statuses:
                failures.append({"id": probe_id, "reason": f"unexpected status {status_code}", **probe_result})
                log_handle.write(f"[FAIL] {probe_id}: unexpected status {status_code} for {url}\n")
                continue

            if matched_forbidden:
                failures.append({"id": probe_id, "reason": "matched forbidden body patterns", **probe_result})
                log_handle.write(f"[FAIL] {probe_id}: forbidden body pattern matched for {url}\n")
                continue

            log_handle.write(f"[OK] {probe_id}: status={status_code} url={url}\n")

    status_code = 0 if not failures else 1
    details = {
        "type": "path_probes",
        "base_url": base_url,
        "probe_results": results,
        "failed_probes": failures,
        "log_path": str(log_path),
    }
    return subprocess.CompletedProcess(args=["path_probes"], returncode=status_code, stdout="", stderr=""), details


def execute_crawl_plan(case: dict, plan: dict, runtime_sut: Path, crawl_root: Path) -> dict:
    plan_id = sanitize(str(plan.get("id", "")).strip() or "crawl-plan")
    plan_type = str(plan.get("type", "")).strip()
    plan_dir = crawl_root / plan_id
    plan_dir.mkdir(parents=True, exist_ok=True)
    started = datetime.now(timezone.utc)
    started_monotonic = time.monotonic()

    if plan_type == "follow_links":
        result, details = run_follow_links_plan(plan, plan_dir)
        log_name = "wget_follow_links.log"
    elif plan_type == "php_files":
        result, details = run_php_files_plan(case, plan, runtime_sut, plan_dir)
        log_name = "wget_php_files.log"
    elif plan_type == "path_probes":
        result, details = run_path_probes_plan(plan, plan_dir)
        log_name = "path_probes.log"
    else:
        raise RunnerFailure(
            "crawl_runtime_failure",
            f"Crawl plan {plan_id} uses unsupported type {plan_type!r}",
        )

    finished = datetime.now(timezone.utc)
    finished_monotonic = time.monotonic()
    log_path = plan_dir / log_name
    status = "passed" if result.returncode == 0 else "failed"
    if result.returncode not in (0, 1):
        status = "error"

    failure_items = details.get("failed_urls") or details.get("failed_files") or details.get("failed_probes") or []
    failure_reason = ""
    if status != "passed":
        if failure_items:
            first_failure = failure_items[0]
            if isinstance(first_failure, dict):
                failure_reason = f"{plan_id} failed on {first_failure.get('id', 'unknown')}"
            else:
                failure_reason = f"{plan_id} failed on {first_failure}"
        else:
            failure_reason = f"{plan_id} exited with code {result.returncode}"

    return {
        "id": plan_id,
        "type": plan_type,
        "status": status,
        "started_at": started.isoformat(),
        "finished_at": finished.isoformat(),
        "duration_seconds": round(max(0.0, finished_monotonic - started_monotonic), 3),
        "exit_code": result.returncode,
        "log_path": str(log_path),
        "artifact_root": str(plan_dir),
        "details": details,
        "failure_reason": failure_reason,
        "log_excerpt": log_excerpt(log_path) if status != "passed" else "",
    }


def run_crawl_suite(case: dict, run_root: Path, run_label: str | None) -> dict:
    runtime_sut = RUNTIME_ROOT / "cases" / case["id"] / "sut"
    crawl_root = run_root / "crawl"
    crawl_root.mkdir(parents=True, exist_ok=True)
    plans = case.get("crawl_plans", [])
    started = datetime.now(timezone.utc)
    started_monotonic = time.monotonic()

    if not isinstance(plans, list) or not plans:
        finished = datetime.now(timezone.utc)
        finished_monotonic = time.monotonic()
        reason = f"Case {case['id']} enables crawl but defines no crawl_plans"
        return {
            "suite": "crawl",
            "status": "error",
            "started_at": started.isoformat(),
            "finished_at": finished.isoformat(),
            "duration_seconds": round(max(0.0, finished_monotonic - started_monotonic), 3),
            "exit_code": 2,
            "junit_path": "",
            "log_path": "",
            "tests": 0,
            "failures": 0,
            "errors": 1,
            "skipped": 0,
            "junit_parse_error": None,
            "failed_tests": [{"name": "crawl-config", "class": "crawl", "message": reason}],
            "first_failed_test": {"name": "crawl-config", "class": "crawl", "message": reason},
            "failed_pages": [],
            "crawl_plans": [],
            "failure_classification": "crawl_runtime_failure",
            "failure_reason": reason,
            "log_excerpt": "",
        }

    plan_results = [execute_crawl_plan(case, plan, runtime_sut, crawl_root) for plan in plans]
    finished = datetime.now(timezone.utc)
    finished_monotonic = time.monotonic()
    failed_plan_results = [plan for plan in plan_results if plan["status"] != "passed"]
    status = "passed" if not failed_plan_results else "failed"
    if any(plan["status"] == "error" for plan in failed_plan_results):
        status = "error"

    failed_tests = [
        {
            "name": str(plan["id"]),
            "class": "crawl",
            "message": str(plan.get("failure_reason", "")),
        }
        for plan in failed_plan_results
    ]

    return {
        "suite": "crawl",
        "status": status,
        "started_at": started.isoformat(),
        "finished_at": finished.isoformat(),
        "duration_seconds": round(max(0.0, finished_monotonic - started_monotonic), 3),
        "exit_code": 0 if status == "passed" else 1,
        "junit_path": "",
        "log_path": str(crawl_root),
        "tests": len(plan_results),
        "failures": len([plan for plan in plan_results if plan["status"] == "failed"]),
        "errors": len([plan for plan in plan_results if plan["status"] == "error"]),
        "skipped": 0,
        "junit_parse_error": None,
        "failed_tests": failed_tests,
        "first_failed_test": first_failed_test(failed_tests),
        "failed_pages": [],
        "crawl_plans": plan_results,
        "failure_classification": suite_failure_classification("crawl", status),
        "failure_reason": suite_failure_reason("crawl", failed_tests, []),
        "log_excerpt": "\n\n".join(plan["log_excerpt"] for plan in failed_plan_results if plan.get("log_excerpt")),
    }


def suite_failure_classification(suite: str, status: str) -> str | None:
    if status == "passed":
        return None
    if suite == "smoke":
        return "smoke_http_runtime_failure"
    if suite == "crawl":
        return "crawl_runtime_failure"
    return "phpunit_test_failure"


def suite_failure_reason(suite: str, failed_tests: list[dict], failed_pages: list[dict]) -> str | None:
    if suite == "smoke" and failed_pages:
        page = failed_pages[0]
        return f"Smoke page {page.get('page_id', 'unknown')} failed"
    if suite == "crawl":
        first = first_failed_test(failed_tests)
        if first:
            return f"Crawl plan {first.get('name', 'unknown')} failed"
        return None
    first = first_failed_test(failed_tests)
    if first:
        return f"{first.get('class', '')}::{first.get('name', '')}".strip(":")
    return None


def run_suite(case: dict, suite: str, run_root: Path, test_filter: str | None, run_label: str | None) -> dict:
    if suite == "crawl":
        return run_crawl_suite(case, run_root, run_label)
    if suite not in PHPUNIT_SUITES:
        started = datetime.now(timezone.utc)
        finished = datetime.now(timezone.utc)
        reason = f"Unknown suite: {suite}"
        failed_test = {"name": suite, "class": "suite", "message": reason}
        return {
            "suite": suite,
            "status": "error",
            "started_at": started.isoformat(),
            "finished_at": finished.isoformat(),
            "duration_seconds": 0.0,
            "exit_code": 2,
            "junit_path": "",
            "log_path": "",
            "tests": 0,
            "failures": 0,
            "errors": 1,
            "skipped": 0,
            "junit_parse_error": None,
            "failed_tests": [failed_test],
            "first_failed_test": failed_test,
            "failed_pages": [],
            "failure_classification": "phpunit_test_failure",
            "failure_reason": reason,
            "log_excerpt": "",
        }

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
    if parsed.get("parse_error"):
        status = "error"

    failed_pages = extract_smoke_failures(parsed["failed_tests"])
    failure_classification = suite_failure_classification(suite, status)
    failure_reason = suite_failure_reason(suite, parsed["failed_tests"], failed_pages)
    if parsed.get("parse_error") and not failure_reason:
        failure_reason = f"JUnit parse error for suite {suite}: {parsed['parse_error']}"

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
        "junit_parse_error": parsed.get("parse_error"),
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
    sut_context = summary.get("sut_context") or {}
    lines = [
        f"# Test Summary: {summary['case_id']}",
        "",
        f"- Status: `{summary['status']}`",
        f"- SUT path: `{summary['sut_source']}`",
        f"- Context label: `{summary.get('context_label', '')}`",
        f"- Customization: `{summary['customization']}`",
        f"- Config profile: `{summary['config_profile']}`",
        f"- Started: `{summary['started_at']}`",
        f"- Finished: `{summary['finished_at']}`",
        f"- Run label: `{summary['run_label']}`",
    ]
    if sut_context.get("type"):
        lines.append(f"- SUT context type: `{sut_context['type']}`")
    if sut_context.get("pr_number"):
        lines.append(f"- PR number: `{sut_context['pr_number']}`")
    if sut_context.get("pr_head_ref"):
        lines.append(f"- PR head ref: `{sut_context['pr_head_ref']}`")
    if sut_context.get("pr_base_ref"):
        lines.append(f"- PR base ref: `{sut_context['pr_base_ref']}`")
    if sut_context.get("branch"):
        lines.append(f"- Branch: `{sut_context['branch']}`")
    if sut_context.get("commit_sha"):
        lines.append(f"- Commit: `{sut_context['commit_sha']}`")
    if "dirty" in sut_context:
        lines.append(f"- Dirty worktree: `{sut_context['dirty']}`")
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
        if suite.get("junit_path"):
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
        for plan in suite.get("crawl_plans", []):
            lines.append(f"  crawl plan: `{plan['id']}` `{plan['status']}`")
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def update_latest_pointers(case_id: str, summary: dict) -> None:
    context_label = sanitize(summary.get("context_label", "")) if summary.get("context_label") else ""
    write_json(REPORTS_ROOT / "summary" / "latest.json", summary)
    write_markdown(REPORTS_ROOT / "summary" / f"{case_id}-latest.md", summary)
    write_json(REPORTS_ROOT / "cases" / case_id / "latest.json", summary)
    if context_label:
        write_json(REPORTS_ROOT / "summary" / "contexts" / context_label / "latest.json", summary)
        write_json(REPORTS_ROOT / "cases" / case_id / "contexts" / context_label / "latest.json", summary)
    if summary["status"] == "failed":
        write_json(REPORTS_ROOT / "summary" / "latest-failed.json", summary)
        write_json(REPORTS_ROOT / "cases" / case_id / "latest-failed.json", summary)
        if context_label:
            write_json(REPORTS_ROOT / "summary" / "contexts" / context_label / "latest-failed.json", summary)
            write_json(REPORTS_ROOT / "cases" / case_id / "contexts" / context_label / "latest-failed.json", summary)


def finalize_summary(
    case: dict,
    profile: dict,
    requested_suites: list[str],
    suite_results: list[dict],
    run_root: Path,
    run_label: str | None,
    setup_result: dict,
) -> dict:
    sut_context = load_sut_context()
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
        "crawl_plans": case.get("crawl_plans", []),
        "suite_results": suite_results,
        "setup_result": setup_result,
        "started_at": started,
        "finished_at": finished,
        "sut_source": str(SUT_SOURCE),
        "context_label": sut_context.get("label", ""),
        "sut_context": sut_context,
        "run_label": run_label or "",
        "branch": sut_context.get("branch") or git_value(["git", "-C", str(SUT_SOURCE), "rev-parse", "--abbrev-ref", "HEAD"]),
        "commit_sha": sut_context.get("commit_sha") or git_value(["git", "-C", str(SUT_SOURCE), "rev-parse", "HEAD"]),
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
    if summary["context_label"]:
        context_label = sanitize(summary["context_label"])
        summary["artifact_paths"]["context_latest_json"] = str(REPORTS_ROOT / "summary" / "contexts" / context_label / "latest.json")
        summary["artifact_paths"]["context_latest_failed_json"] = str(
            REPORTS_ROOT / "summary" / "contexts" / context_label / "latest-failed.json"
        )
        summary["artifact_paths"]["case_context_latest_json"] = str(
            REPORTS_ROOT / "cases" / case["id"] / "contexts" / context_label / "latest.json"
        )
        summary["artifact_paths"]["case_context_latest_failed_json"] = str(
            REPORTS_ROOT / "cases" / case["id"] / "contexts" / context_label / "latest-failed.json"
        )
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
