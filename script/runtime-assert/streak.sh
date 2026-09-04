#!/usr/bin/env bash
#
# Honest streak ledger for #36397 Done-when (7 consecutive dual green days).
# Empty ledger / skip-without-valgrind is NOT a pass (artifact-honesty).
#
# Usage:
#   ./script/runtime-assert/streak.sh status
#   ./script/runtime-assert/streak.sh record   # run smokes; append UTC day only on real pass
#   ./script/runtime-assert/streak.sh check    # fail unless dual consecutive streak >= NEED (default 7)
#
# Env:
#   STREAK_NEED=7
#   STREAK_JSON=test/runtime-assert/STREAK.json
#   STREAK_SKIP_RUN=1   # record without re-running (CI host already ran smokes; still requires markers)
#
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

LEDGER="${STREAK_JSON:-$ROOT/test/runtime-assert/STREAK.json}"
NEED="${STREAK_NEED:-7}"
CMD="${1:-status}"

if [[ ! -f "$LEDGER" ]]; then
  echo "runtime-assert-streak: FAIL — missing $LEDGER" >&2
  exit 1
fi

today_utc() { date -u +%Y-%m-%d; }

# Print consecutive dual streak ending at the latest day present in BOTH lists (0 if empty).
dual_streak_py() {
  python3 - "$LEDGER" <<'PY'
import json, sys
from datetime import datetime, timedelta, timezone

path = sys.argv[1]
with open(path, encoding="utf-8") as f:
    data = json.load(f)
asan = set(data.get("asan_ok_days") or [])
vg = set(data.get("valgrind_ok_days") or [])
both = sorted(asan & vg)
if not both:
    print(0)
    sys.exit(0)
# Walk backward from the newest shared day.
end = datetime.strptime(both[-1], "%Y-%m-%d").replace(tzinfo=timezone.utc)
streak = 0
d = end
while d.strftime("%Y-%m-%d") in asan and d.strftime("%Y-%m-%d") in vg:
    streak += 1
    d -= timedelta(days=1)
print(streak)
PY
}

append_day_py() {
  local field="$1"
  local day="$2"
  python3 - "$LEDGER" "$field" "$day" <<'PY'
import json, sys
from datetime import datetime, timezone

path, field, day = sys.argv[1], sys.argv[2], sys.argv[3]
with open(path, encoding="utf-8") as f:
    data = json.load(f)
days = list(data.get(field) or [])
if day not in days:
    days.append(day)
    days.sort()
data[field] = days
data["updated_at"] = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
# Never claim a streak from empty arrays — keep the honesty note.
note = data.get("note") or ""
if "empty" not in note.lower():
    data["note"] = (
        "Local ledger for #36397 Done-when (7 consecutive asan_ok_days ∩ valgrind_ok_days). "
        "Append UTC YYYY-MM-DD only after a real green smoke — empty ≠ pass; "
        "valgrind SKIP_NO_VALGRIND must not append valgrind_ok_days."
    )
with open(path, "w", encoding="utf-8") as f:
    json.dump(data, f, indent=2)
    f.write("\n")
print(f"appended {field} {day}")
PY
}

run_asan() {
  echo "runtime-assert-streak: running asan-smoke…"
  ./script/runtime-assert/asan-smoke.sh
}

# Returns 0=pass, 1=fail, 2=skip (no valgrind).
run_valgrind() {
  echo "runtime-assert-streak: running valgrind-smoke…"
  local out rc
  set +e
  out="$(./script/runtime-assert/valgrind-smoke.sh 2>&1)"
  rc=$?
  set -e
  printf '%s\n' "$out"
  if printf '%s\n' "$out" | grep -q 'SKIP_NO_VALGRIND'; then
    return 2
  fi
  return "$rc"
}

case "$CMD" in
  status)
    streak="$(dual_streak_py)"
    python3 - "$LEDGER" "$streak" "$NEED" <<'PY'
import json, sys
path, streak, need = sys.argv[1], int(sys.argv[2]), int(sys.argv[3])
with open(path, encoding="utf-8") as f:
    data = json.load(f)
asan = data.get("asan_ok_days") or []
vg = data.get("valgrind_ok_days") or []
print(f"runtime-assert-streak: dual_consecutive={streak} need={need}")
print(f"  asan_ok_days={len(asan)} valgrind_ok_days={len(vg)} updated_at={data.get('updated_at')}")
if streak == 0:
    print("  (empty intersection is not a pass — #36397 artifact-honesty)")
PY
    ;;
  record)
    day="$(today_utc)"
    asan_ok=0
    vg_ok=0
    vg_skip=0
    if [[ "${STREAK_SKIP_RUN:-0}" != "1" ]]; then
      if run_asan; then
        asan_ok=1
      else
        echo "runtime-assert-streak: asan-smoke failed — not appending asan_ok_days" >&2
        exit 1
      fi
      set +e
      run_valgrind
      vg_rc=$?
      set -e
      if [[ "$vg_rc" -eq 0 ]]; then
        vg_ok=1
      elif [[ "$vg_rc" -eq 2 ]]; then
        vg_skip=1
        echo "runtime-assert-streak: valgrind missing — NOT appending valgrind_ok_days (skip ≠ pass)"
      else
        echo "runtime-assert-streak: valgrind-smoke failed — not appending valgrind_ok_days" >&2
        exit 1
      fi
    else
      echo "runtime-assert-streak: STREAK_SKIP_RUN=1 — refusing to invent days without a real smoke" >&2
      echo "runtime-assert-streak: use record without STREAK_SKIP_RUN, or append only after host proof" >&2
      exit 2
    fi
    if [[ "$asan_ok" -eq 1 ]]; then
      append_day_py asan_ok_days "$day"
    fi
    if [[ "$vg_ok" -eq 1 ]]; then
      append_day_py valgrind_ok_days "$day"
    fi
    streak="$(dual_streak_py)"
    echo "runtime-assert-streak: recorded day=$day asan=$asan_ok valgrind=$vg_ok skip_vg=$vg_skip dual_consecutive=$streak"
    ;;
  check)
    streak="$(dual_streak_py)"
    echo "runtime-assert-streak: dual_consecutive=$streak need=$NEED"
    if [[ "$streak" -lt "$NEED" ]]; then
      echo "runtime-assert-streak: FAIL — need $NEED consecutive dual green days (empty ledger is not a pass)" >&2
      exit 1
    fi
    echo "runtime-assert-streak: OK ($streak/$NEED)"
    ;;
  *)
    echo "usage: $0 {status|record|check}" >&2
    exit 2
    ;;
esac
