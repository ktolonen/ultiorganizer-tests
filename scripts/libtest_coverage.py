"""Host-side per-file coverage analysis for libtest:coverage."""

from __future__ import annotations

import re
import xml.etree.ElementTree as ET
from pathlib import Path


def parse_clover_for_lib_file(clover_path: Path, lib_filename: str) -> dict[int, int]:
    """Return {line_num: execution_count} for the named lib file from a clover XML.

    Matches on the lib/<filename> suffix so container paths work unchanged.
    Returns an empty dict if the file is not found in the clover data.
    """
    try:
        root = ET.parse(clover_path).getroot()
    except (ET.ParseError, OSError):
        return {}
    suffix = f"lib/{lib_filename}"
    for file_el in root.iter("file"):
        name = file_el.get("name", "")
        if name.endswith(suffix):
            return {
                int(ln.get("num")): int(ln.get("count", 0))
                for ln in file_el.iter("line")
                if ln.get("type") == "stmt"
            }
    return {}


def parse_php_functions(source_path: Path) -> list[tuple[str, int]]:
    """Return [(function_name, line_number), ...] for top-level PHP functions.

    Matches lines of the form:  function FuncName(
    Line numbers are 1-based, matching clover's convention.
    """
    pattern = re.compile(r"^function\s+(\w+)\s*\(")
    results = []
    try:
        lines = source_path.read_text(encoding="utf-8", errors="replace").splitlines()
    except OSError:
        return []
    for i, line in enumerate(lines, 1):
        m = pattern.match(line)
        if m:
            results.append((m.group(1), i))
    return results


def _function_coverage(
    line_counts: dict[int, int],
    functions: list[tuple[str, int]],
) -> tuple[list[str], list[dict]]:
    """Compute uncovered and partial function lists.

    A function is covered if the first tracked stmt line inside its body has
    execution count > 0.  A function is partial if it is covered but has at
    least one stmt line inside its body with count == 0.
    """
    sorted_stmts = sorted(line_counts.keys())
    uncovered: list[str] = []
    partial: list[dict] = []

    for idx, (fname, fline) in enumerate(functions):
        next_fline = functions[idx + 1][1] if idx + 1 < len(functions) else float("inf")
        body_stmts = [ln for ln in sorted_stmts if fline < ln < next_fline]
        if not body_stmts:
            uncovered.append(fname)
            continue
        first_count = line_counts[body_stmts[0]]
        if first_count == 0:
            uncovered.append(fname)
        else:
            zero_stmts = sum(1 for ln in body_stmts if line_counts[ln] == 0)
            if zero_stmts > 0:
                total_body = len(body_stmts)
                covered_body = total_body - zero_stmts
                partial.append(
                    {
                        "name": fname,
                        "pct": round(covered_body / total_body * 100, 1),
                        "covered": covered_body,
                        "total": total_body,
                    }
                )

    return uncovered, partial


def build_coverage_json(
    clover_path: Path,
    source_path: Path,
    catalog_entry: dict,
    global_targets: dict,
) -> dict:
    """Build the full coverage JSON dict for one lib file."""
    lib_file = catalog_entry["lib_file"]
    line_target = catalog_entry.get("line_target", global_targets.get("line_pct", 80))
    fn_target = catalog_entry.get("function_target", global_targets.get("function_pct", 100))

    line_counts = parse_clover_for_lib_file(clover_path, lib_file)
    functions = parse_php_functions(source_path)

    total_stmts = len(line_counts)
    covered_stmts = sum(1 for c in line_counts.values() if c > 0)
    line_pct = round(covered_stmts / total_stmts * 100, 1) if total_stmts else 0.0

    total_fns = len(functions)
    uncovered, partial = _function_coverage(line_counts, functions)
    covered_fns = total_fns - len(uncovered)
    fn_pct = round(covered_fns / total_fns * 100, 1) if total_fns else 0.0

    return {
        "lib_file": lib_file,
        "line": {
            "pct": line_pct,
            "covered": covered_stmts,
            "total": total_stmts,
            "meets_target": line_pct >= line_target,
        },
        "functions": {
            "pct": fn_pct,
            "covered": covered_fns,
            "total": total_fns,
            "meets_target": covered_fns == total_fns,
        },
        "uncovered": uncovered,
        "partial": partial,
        "targets": {"line_pct": line_target, "function_pct": fn_target},
    }
