#!/usr/bin/env python3

from __future__ import annotations

import argparse
import fcntl
import json
import os
import subprocess
import sys
import time
from contextlib import contextmanager
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
REPORTS_ROOT = ROOT / "reports"
MATRIX_CONFIG = ROOT / "config" / "matrix.json"
LOCK_PATH = ROOT / ".runtime" / "harness.lock"
SIBLING_DEFAULT_SUT_PATH = (ROOT.parent / "ultiorganizer").resolve()
REQUIRED_SUT_PATHS = [
    "index.php",
    "lib/database.php",
    "sql/ultiorganizer.sql",
    "cust/default",
]


class HarnessError(RuntimeError):
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


def default_sut_path() -> str:
    if SIBLING_DEFAULT_SUT_PATH.is_dir():
        return str(SIBLING_DEFAULT_SUT_PATH)
    return str(SIBLING_DEFAULT_SUT_PATH)


def normalize_sut_path(sut_path: str) -> str:
    return str(Path(sut_path).expanduser().resolve())


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
    for suite in summary.get("suite_results", []):
        logs[suite["suite"]] = suite["log_path"]
    return {
        "case_id": case_id,
        "context_label": summary.get("context_label", ""),
        "status": summary["status"],
        "failure_classification": summary.get("failure_classification"),
        "logs": logs,
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
        suites="unit,integration",
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
    suite.add_argument("--suite", required=True, choices=["unit", "integration", "smoke"])
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

    return parser


def main() -> int:
    parser = build_parser()
    args = parser.parse_args()
    return args.func(args)


if __name__ == "__main__":
    sys.exit(main())
