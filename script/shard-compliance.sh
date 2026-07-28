#!/usr/bin/env bash
#
# Shard a compliance suite so it is runnable per-change (RELEASE-PLAN Phase 1.3).
#
# VMTest is ~4 hours serial and carries ~400 pre-existing failures, so in practice nobody runs it
# per-change and regressions land unnoticed. Sharding the case list across N containers turns that
# into ~20 minutes wall.
#
# Four traps this handles, each of which has cost a run before:
#
#   1. Case names are relative paths and contain '/'. A raw '/' inside a /.../ PHP regex closes the
#      delimiter early, the filter matches nothing, and PHPUnit reports "No tests executed!" with
#      EXIT CODE 0 — which reads as success. Names are escaped, and an empty selection is a failure.
#   2. --list-tests IGNORES --filter, so a shard's selection cannot be verified that way. The script
#      reports the executed count instead and fails when it is zero.
#   3. Default PHPUnit output prints failure detail only at the END, so a stalled or killed shard
#      yields nothing. --teamcity streams per-test events, so results up to the stall survive.
#   4. Some cases hang outright (stdlib/password_needs_rehash under the interpreted VM). Every shard
#      is wrapped in a timeout so one hang cannot block the set.
#
# Compare runs by failing case NAME, never by count — neither suite is green (AGENTS.md §2).
#
# Usage:
#   script/shard-compliance.sh --suite=VMTest --shards=24 --shard=3
#   script/shard-compliance.sh --suite=VMTest --shards=24 --list      # names only, no run
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT" || exit 1

SUITE="VMTest"
SHARDS=24
SHARD=0
LIST_ONLY=0
: "${SHARD_TIMEOUT:=3600}"
: "${PHP_BIN:=php}"

for arg in "$@"; do
    case "$arg" in
        --suite=*)  SUITE="${arg#*=}" ;;
        --shards=*) SHARDS="${arg#*=}" ;;
        --shard=*)  SHARD="${arg#*=}" ;;
        --list)     LIST_ONLY=1 ;;
        *) echo "unknown argument: $arg" >&2; exit 2 ;;
    esac
done

TEST_FILE="test/compliance/${SUITE}.php"
if [ ! -f "$TEST_FILE" ]; then
    echo "shard-compliance: no such suite: $TEST_FILE" >&2
    exit 2
fi
if [ "$SHARD" -ge "$SHARDS" ] || [ "$SHARD" -lt 0 ]; then
    echo "shard-compliance: --shard=$SHARD out of range for --shards=$SHARDS" >&2
    exit 2
fi

CASES_DIR="test/compliance/cases"

# Case name == path under cases/ with .phpt stripped, matching BaseTest::providePHPTestsFromDir().
# Sorted so shard membership is deterministic across machines and runs.
mapfile -t ALL_CASES < <(
    find "$CASES_DIR" -name '*.phpt' -type f \
        | sed "s|^${CASES_DIR}/||; s|\.phpt$||" \
        | LC_ALL=C sort
)

total="${#ALL_CASES[@]}"
if [ "$total" -eq 0 ]; then
    echo "shard-compliance: found no .phpt cases under $CASES_DIR" >&2
    exit 2
fi

# Round-robin by index: stdlib is 63% of the corpus, so contiguous slicing would leave one shard
# with nothing but stdlib and another with none of it. Interleaving keeps shards comparable.
SHARD_CASES=()
for i in "${!ALL_CASES[@]}"; do
    if [ $((i % SHARDS)) -eq "$SHARD" ]; then
        SHARD_CASES+=("${ALL_CASES[$i]}")
    fi
done

count="${#SHARD_CASES[@]}"
if [ "$count" -eq 0 ]; then
    echo "shard-compliance: shard $SHARD of $SHARDS selected 0 cases from $total" >&2
    exit 1
fi

if [ "$LIST_ONLY" -eq 1 ]; then
    printf '%s\n' "${SHARD_CASES[@]}"
    echo "# shard $SHARD/$SHARDS: $count of $total cases" >&2
    exit 0
fi

# PHPUnit names a data-provider case `Suite::test with data set "<name>"`. Escape regex
# metacharacters AND the '/' delimiter — trap 1.
escape_case() {
    printf '%s' "$1" | sed -e 's/[][\.*^$(){}?+|]/\\&/g' -e 's|/|\\/|g'
}

alternation=""
for name in "${SHARD_CASES[@]}"; do
    esc="$(escape_case "$name")"
    if [ -z "$alternation" ]; then
        alternation="$esc"
    else
        alternation="${alternation}|${esc}"
    fi
done
FILTER="/ with data set \"(?:${alternation})\"$/"

LOG_DIR="build/compliance-shards"
mkdir -p "$LOG_DIR"
LOG="${LOG_DIR}/${SUITE}-${SHARD}-of-${SHARDS}.log"
FAILED_FILE="${LOG_DIR}/${SUITE}-${SHARD}-of-${SHARDS}.failed"

echo "shard-compliance: ${SUITE} shard ${SHARD}/${SHARDS} — ${count} of ${total} cases, timeout ${SHARD_TIMEOUT}s"

# --teamcity so a stalled shard still reports what it got through — trap 3.
timeout "$SHARD_TIMEOUT" vendor/bin/phpunit \
    --no-coverage \
    --teamcity \
    --filter "$FILTER" \
    "$TEST_FILE" > "$LOG" 2>&1
rc=$?

# testStarted events are the honest executed count; --list-tests cannot be used here — trap 2.
# NOTE: `grep -c ... || echo 0` is WRONG here — grep -c already prints 0 and exits 1 when it
# matches nothing, so the fallback appends a second value, the integer test below errors, and the
# zero-executed guard never fires. That is the exact bug this guard exists to catch, so it is worth
# the comment.
executed="$(grep -c "^##teamcity\[testStarted" "$LOG" 2>/dev/null || true)"
executed="${executed:-0}"
grep -oE "^##teamcity\[testFailed name='[^']*'" "$LOG" 2>/dev/null \
    | sed "s/.*name='//; s/'$//" \
    | LC_ALL=C sort -u > "$FAILED_FILE"
failed="$(wc -l < "$FAILED_FILE" | tr -d ' ')"

if [ "$rc" -eq 124 ]; then
    echo "shard-compliance: TIMEOUT after ${SHARD_TIMEOUT}s — ${executed} executed, ${failed} failed before the stall" >&2
    echo "  partial results kept in ${FAILED_FILE} (this is why --teamcity is used)" >&2
    exit 124
fi

if [ "$executed" -eq 0 ]; then
    echo "shard-compliance: NO TESTS EXECUTED — the filter matched nothing." >&2
    echo "  PHPUnit exits 0 in this case, so it would otherwise read as success. Check the filter" >&2
    echo "  escaping in ${LOG}." >&2
    exit 1
fi

echo "shard-compliance: ${SUITE} shard ${SHARD}/${SHARDS} — ${executed} executed, ${failed} failed"
echo "  failing names: ${FAILED_FILE}"
echo "  full log:      ${LOG}"

# Deliberately exit 0 on test failures: neither suite is green, so a non-zero exit here would mean
# "this suite has failures", which is always true and therefore useless. Compare .failed files
# between runs by NAME instead.
exit 0
