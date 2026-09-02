#!/usr/bin/env python3
"""Binary + IR size regression gate for script/aot-smoke.sh (#36197)."""

from __future__ import annotations

import argparse
import json
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


def section_bytes(binary: Path, section: str) -> int:
    out = subprocess.check_output(
        ["size", "-A", str(binary)],
        text=True,
        errors="replace",
    )
    for line in out.splitlines():
        parts = line.split()
        if len(parts) >= 2 and parts[0] == section:
            return int(parts[1])
    return 0


def needed_count(binary: Path) -> int:
    out = subprocess.check_output(
        ["readelf", "-d", str(binary)],
        text=True,
        errors="replace",
    )
    return sum(1 for line in out.splitlines() if "(NEEDED)" in line)


def init_array_sections(binary: Path) -> int:
    out = subprocess.check_output(
        ["readelf", "-S", str(binary)],
        text=True,
        errors="replace",
    )
    return sum(1 for line in out.splitlines() if ".init_array" in line)


def measure_binary(binary: Path) -> dict[str, int]:
    return {
        "file_bytes": binary.stat().st_size,
        "text": section_bytes(binary, ".text"),
        "data": section_bytes(binary, ".data"),
        "bss": section_bytes(binary, ".bss"),
        "needed_count": needed_count(binary),
        "init_array": init_array_sections(binary),
    }


def measure_ir(ir_path: Path) -> dict[str, int]:
    text = ir_path.read_text(encoding="utf-8", errors="replace")
    lines = text.count("\n") + (0 if text.endswith("\n") or text == "" else 1)
    defines = sum(1 for line in text.splitlines() if line.startswith("define "))
    return {"ir_lines": lines, "ir_defines": defines}


def compare(
    baseline: dict[str, Any],
    measured: dict[str, dict[str, int]],
    tolerance_percent: int,
) -> list[str]:
    errors: list[str] = []
    base_cases = baseline.get("cases", {})
    for name, current in measured.items():
        if name not in base_cases:
            errors.append(f"{name}: no baseline entry")
            continue
        base = base_cases[name]
        for key, value in current.items():
            if key not in base:
                continue
            base_value = int(base[key])
            if base_value <= 0:
                continue
            growth = (value - base_value) * 100.0 / base_value
            if growth > tolerance_percent:
                errors.append(
                    f"{name}.{key}: {value} exceeds baseline {base_value} "
                    f"by {growth:.1f}% (limit {tolerance_percent}%)"
                )
    return errors


def print_table(measured: dict[str, dict[str, int]]) -> None:
    print("aot-smoke size table (#36197):")
    header = (
        f"{'case':<8} {'file_bytes':>12} {'text':>12} {'data':>10} "
        f"{'bss':>12} {'needed':>6} {'init_arr':>8} {'ir_lines':>10} {'defines':>8}"
    )
    print(header)
    for name, row in sorted(measured.items()):
        print(
            f"{name:<8} {row.get('file_bytes', 0):>12} {row.get('text', 0):>12} "
            f"{row.get('data', 0):>10} {row.get('bss', 0):>12} "
            f"{row.get('needed_count', 0):>6} {row.get('init_array', 0):>8} "
            f"{row.get('ir_lines', 0):>10} {row.get('ir_defines', 0):>8}"
        )


def main() -> int:
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest="cmd", required=True)

    check = sub.add_parser("check")
    check.add_argument("--baseline", type=Path, required=True)
    check.add_argument("--measurements", type=Path, required=True)

    update = sub.add_parser("update")
    update.add_argument("--baseline", type=Path, required=True)
    update.add_argument("--measurements", type=Path, required=True)
    update.add_argument("--arch", default="x86_64-linux")

    measure = sub.add_parser("measure")
    measure.add_argument("--binary", type=Path, required=True)
    measure.add_argument("--ir", type=Path)
    measure.add_argument("--out", type=Path, required=True)
    measure.add_argument("--name", required=True)

    args = parser.parse_args()

    if args.cmd == "measure":
        row = measure_binary(args.binary)
        if args.ir is not None and args.ir.is_file():
            row.update(measure_ir(args.ir))
        payload: dict[str, Any] = {}
        if args.out.is_file():
            payload = json.loads(args.out.read_text(encoding="utf-8"))
        payload[args.name] = row
        args.out.write_text(json.dumps(payload, indent=4) + "\n", encoding="utf-8")
        return 0

    measured = json.loads(args.measurements.read_text(encoding="utf-8"))
    baseline = json.loads(args.baseline.read_text(encoding="utf-8"))
    print_table(measured)

    if args.cmd == "update":
        doc = {
            "version": 1,
            "generated_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S+00:00"),
            "arch": args.arch,
            "tolerance_percent": int(baseline.get("tolerance_percent", 10)),
            "cases": measured,
        }
        args.baseline.write_text(json.dumps(doc, indent=4) + "\n", encoding="utf-8")
        print(f"aot-smoke: updated {args.baseline}")
        return 0

    tolerance = int(baseline.get("tolerance_percent", 10))
    errors = compare(baseline, measured, tolerance)
    if errors:
        print("aot-smoke: SIZE REGRESSION", file=sys.stderr)
        for err in errors:
            print(f"  {err}", file=sys.stderr)
        return 1
    print(f"aot-smoke: size gate OK (<= {tolerance}% growth vs baseline)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
