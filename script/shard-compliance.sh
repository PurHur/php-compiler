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

# Override for unit tests (#24498); production stays on the real corpus.
: "${COMPLIANCE_CASES_DIR:=test/compliance/cases}"
CASES_DIR="$COMPLIANCE_CASES_DIR"

# Case name == path under cases/ with .phpt stripped, matching BaseTest::providePHPTestsFromDir().
# Sorted so listing order is deterministic across machines and runs (membership itself is hash-stable).
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

# Hash-stable membership (#24498): shard = crc32(case_name) % SHARDS.
# Index round-robin reshuffled every case after a corpus add/remove/disable; hashing a case name
# moves only that case. Shards are slightly uneven by count — fine, cost is not uniform anyway.
# One PHP process for the whole list (not one spawn per case).
mapfile -t SHARD_CASES < <(
    printf '%s\n' "${ALL_CASES[@]}" | SHARDS="$SHARDS" SHARD="$SHARD" "$PHP_BIN" -r '
        $shards = (int) getenv("SHARDS");
        $shard = (int) getenv("SHARD");
        if ($shards < 1) {
            fwrite(STDERR, "shard-compliance: invalid SHARDS\n");
            exit(2);
        }
        while (($line = fgets(STDIN)) !== false) {
            $name = rtrim($line, "\r\n");
            if ($name === "") {
                continue;
            }
            if (((crc32($name) & 0xffffffff) % $shards) === $shard) {
                echo $name, "\n";
            }
        }
    '
)

count="${#SHARD_CASES[@]}"
if [ "$LIST_ONLY" -eq 1 ]; then
    # Empty is allowed for --list (tiny fixtures / unlucky hash buckets); real runs still fail below.
    if [ "$count" -gt 0 ]; then
        printf '%s\n' "${SHARD_CASES[@]}"
    fi
    echo "# shard $SHARD/$SHARDS: $count of $total cases" >&2
    exit 0
fi

if [ "$count" -eq 0 ]; then
    echo "shard-compliance: shard $SHARD of $SHARDS selected 0 cases from $total" >&2
    exit 1
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

TIMEOUT_MARKER="${LOG_DIR}/${SUITE}-${SHARD}-of-${SHARDS}.timeout"
rm -f "$TIMEOUT_MARKER"
if [ "$rc" -eq 124 ]; then
    # A timed-out shard leaves a .failed file that looks complete. Collecting it would silently
    # bake a PARTIAL shard into the baseline, and every case it never reached would later read as
    # newly-passing. Drop a marker so compliance-baseline.sh refuses the set.
    last_started="$(grep -oE "^##teamcity\[testStarted name='[^']*'" "$LOG" 2>/dev/null | tail -1 | sed "s/.*name='//; s/'$//")"
    {
        echo "timeout after ${SHARD_TIMEOUT}s"
        echo "executed=${executed} failed_before_stall=${failed}"
        echo "stalled_on=${last_started:-<unknown>}"
    } > "$TIMEOUT_MARKER"
    echo "shard-compliance: TIMEOUT after ${SHARD_TIMEOUT}s — ${executed} executed, ${failed} failed before the stall" >&2
    echo "  stalled on: ${last_started:-<unknown>}" >&2
    echo "  partial results kept in ${FAILED_FILE}; ${TIMEOUT_MARKER} marks the set incomplete" >&2
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
