#!/usr/bin/env bash
#
# Turn sharded compliance runs into a regression gate (RELEASE-PLAN Phase 1.3 / 1.4).
#
# script/shard-compliance.sh makes the suites runnable; it does not make them a gate. Neither suite
# is green — VMTest carries ~400 pre-existing failures — so "did it fail?" is always yes and tells
# you nothing. The gate is the SET DIFFERENCE of failing case NAMES against a committed baseline
# (AGENTS.md §2).
#
#   script/compliance-baseline.sh --collect --suite=VMTest    # shards -> baseline file
#   script/compliance-baseline.sh --diff    --suite=VMTest    # current shards vs committed baseline
#
# Quarantine: test/compliance/quarantine.txt lists cases that flip between runs of the SAME commit.
# They are excluded from both sides of the diff, because a flaky case in a baseline manufactures
# phantom regressions — which has already cost real time on the differential corpus (#24226, and an
# e08_spread "regression" that turned out to be 17/30 vs 17/30 noise).
#
# Entry to the quarantine is a BUG, not a resting place: each line carries a reason and an issue.
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT" || exit 1

SUITE="VMTest"
MODE=""
# Overridable for tests (#24498 does the same for the case corpus); production uses the defaults.
: "${SHARD_DIR:=build/compliance-shards}"
: "${BASELINE_DIR:=test/compliance/baselines}"
: "${QUARANTINE:=test/compliance/quarantine.txt}"

for arg in "$@"; do
    case "$arg" in
        --suite=*) SUITE="${arg#*=}" ;;
        --collect) MODE="collect" ;;
        --diff)    MODE="diff" ;;
        *) echo "unknown argument: $arg" >&2; exit 2 ;;
    esac
done

if [ -z "$MODE" ]; then
    echo "usage: $0 (--collect|--diff) [--suite=VMTest]" >&2
    exit 2
fi

BASELINE="${BASELINE_DIR}/${SUITE}.failing"
EXECUTED_BASELINE="${BASELINE_DIR}/${SUITE}.executed"

# Quarantined names for THIS suite, comments and blanks stripped.
#
# Flakiness is suite-specific, so the quarantine has to be too. A line may be scoped:
#
#   VMTest:stdlib/hrtime_nanosecond_precision   # only quarantined under VMTest
#   stdlib/something                            # quarantined under every suite
#
# Without scoping, one entry hides the case everywhere. Measured: hrtime_nanosecond_precision flips
# between VMTest runs of one commit (#24870) but fails STABLY under JITTest in both runs of
# e7de99700 — quarantining it globally dropped a stable JITTest failure from the baseline, which is
# the same "real failure made invisible" harm the quarantine itself caused in #24726.
quarantined() {
    if [ -f "$QUARANTINE" ]; then
        sed -e 's/#.*//' -e 's/[[:space:]]*$//' "$QUARANTINE" \
            | grep -v '^$' \
            | awk -v suite="$SUITE" '
                {
                    line = $0
                    idx = index(line, ":")
                    if (idx > 0) {
                        scope = substr(line, 1, idx - 1)
                        name = substr(line, idx + 1)
                        # A scope is only a scope if it names a suite; case names contain no colon.
                        if (scope == suite) { print name }
                        next
                    }
                    print line
                }' \
            | LC_ALL=C sort -u
    fi
}

# Union of every shard's failing names for this suite, normalised to bare case names.
#
# PHPUnit reports a data-provider case as `testCases with data set "stdlib/mkdir"`, but the
# quarantine file and anything human-readable use the bare `stdlib/mkdir`. Without normalising here
# the quarantine matched nothing and silently excluded zero cases while still reporting success —
# caught only because the regenerated baseline count did not drop by the expected amount.
current_failing() {
    local files
    files=$(find "$SHARD_DIR" -name "${SUITE}-*-of-*.failed" 2>/dev/null | LC_ALL=C sort)
    if [ -z "$files" ]; then
        return 1
    fi
    # shellcheck disable=SC2086
    cat $files 2>/dev/null \
        | sed -e 's/^.*data set "//' -e 's/"$//' \
        | grep -v '^$' \
        | LC_ALL=C sort -u
}

# Union of every shard's EXECUTED names, same normalisation.
#
# Failing names alone cannot tell "this case was fixed" from "this case is no longer run". VMTest
# gates cases on CompilerVersion::supports*(), and the default profile has flipped repeatedly
# (6af97e0b4 enabled property hooks, 910820500 re-rejected them; 270fea4ef re-gated asymmetric
# visibility). Each flip changes the yielded set, and a case that vanishes reads as FIXED — so
# regenerating the baseline drops it from tracking silently. Measured at a634624db: four cases the
# diff called FIXED (asymmetric_visibility_write, asymmetric_visibility_public_protected_set,
# static_asymmetric_visibility, match_typed_class_const) do not execute at all.
current_executed() {
    local files
    files=$(find "$SHARD_DIR" -name "${SUITE}-*-of-*.executed" 2>/dev/null | LC_ALL=C sort)
    if [ -z "$files" ]; then
        return 1
    fi
    # shellcheck disable=SC2086
    cat $files 2>/dev/null \
        | sed -e 's/^.*data set "//' -e 's/"$//' \
        | grep -v '^$' \
        | LC_ALL=C sort -u
}

# Results from different --shards=N runs must never be mixed: the union would double-count some
# cases and omit others, and the coverage check would compare against whichever N happened to sort
# first. Refuse rather than silently produce a wrong baseline.
shard_coverage() {
    local widths
    widths=$(find "$SHARD_DIR" -name "${SUITE}-*-of-*.failed" 2>/dev/null \
        | sed -E 's/.*-of-([0-9]+)\.failed/\1/' | LC_ALL=C sort -u)
    local distinct
    distinct=$(printf '%s\n' "$widths" | grep -c '^[0-9]' || true)
    if [ "${distinct:-0}" -gt 1 ]; then
        echo "MIXED $(printf '%s' "$widths" | tr '\n' ',')"
        return
    fi
    local n
    n=$(find "$SHARD_DIR" -name "${SUITE}-*-of-*.failed" 2>/dev/null | wc -l | tr -d ' ')
    echo "${n:-0} ${widths:-0}"
}

if ! current_failing > /tmp/.cb_current 2>/dev/null; then
    echo "compliance-baseline: no ${SUITE} shard results under ${SHARD_DIR}/" >&2
    echo "  run script/shard-compliance.sh --suite=${SUITE} --shards=N --shard=i for every i first" >&2
    exit 2
fi

read -r have want <<< "$(shard_coverage)"
if [ "$have" = "MIXED" ]; then
    echo "compliance-baseline: MIXED shard widths present (${want}) under ${SHARD_DIR}/" >&2
    echo "  Results from different --shards=N runs cannot be unioned. Remove the stale ones." >&2
    exit 2
fi
timeouts=$(find "$SHARD_DIR" -name "${SUITE}-*-of-*.timeout" 2>/dev/null | LC_ALL=C sort)
if [ -n "$timeouts" ]; then
    echo "compliance-baseline: TIMED-OUT SHARDS present — the result set is incomplete:" >&2
    for t in $timeouts; do
        echo "  $(basename "$t"): $(grep '^stalled_on=' "$t" 2>/dev/null | cut -d= -f2-)" >&2
    done
    echo "  A timed-out shard writes a .failed file that LOOKS complete; every case it never" >&2
    echo "  reached would read as newly-passing on the next diff. Fix or disable the hanging case," >&2
    echo "  then re-run those shards." >&2
    exit 2
fi

if [ "$want" -gt 0 ] && [ "$have" -lt "$want" ]; then
    # A partial set looks exactly like "these cases got fixed" on the next diff. Refuse it.
    echo "compliance-baseline: INCOMPLETE — ${have} of ${want} shards have results." >&2
    echo "  Diffing a partial run reports every unrun case as newly-passing. Finish the run first." >&2
    exit 2
fi

quarantined > /tmp/.cb_quarantine || true
LC_ALL=C comm -23 /tmp/.cb_current /tmp/.cb_quarantine > /tmp/.cb_current_net

current_executed > /tmp/.cb_executed 2>/dev/null || : > /tmp/.cb_executed

if [ "$MODE" = "collect" ]; then
    mkdir -p "$BASELINE_DIR"
    {
        echo "# ${SUITE} failing cases — generated by script/compliance-baseline.sh --collect"
        echo "# Compare by NAME (AGENTS.md §2). Neither suite is green; a count means nothing."
        echo "# Quarantined cases (test/compliance/quarantine.txt) are excluded."
        echo "# shards: ${want}   failing: $(wc -l < /tmp/.cb_current_net | tr -d ' ')"
        cat /tmp/.cb_current_net
    } > "$BASELINE"
    echo "compliance-baseline: wrote ${BASELINE} ($(wc -l < /tmp/.cb_current_net | tr -d ' ') failing names, ${want} shards)"

    if [ -s /tmp/.cb_executed ]; then
        {
            echo "# ${SUITE} EXECUTED cases — generated by script/compliance-baseline.sh --collect"
            echo "# Not a pass list. This records which cases the provider yielded, so that a case"
            echo "# which stops being yielded is reported as DROPPED rather than silently as FIXED."
            echo "# shards: ${want}   executed: $(wc -l < /tmp/.cb_executed | tr -d ' ')"
            cat /tmp/.cb_executed
        } > "$EXECUTED_BASELINE"
        echo "compliance-baseline: wrote ${EXECUTED_BASELINE} ($(wc -l < /tmp/.cb_executed | tr -d ' ') executed names)"
    else
        echo "compliance-baseline: WARNING — no .executed shard files found; skipping ${EXECUTED_BASELINE}." >&2
        echo "  Without it the next diff cannot tell a fixed case from one that stopped running." >&2
    fi
    exit 0
fi

if [ ! -f "$BASELINE" ]; then
    echo "compliance-baseline: no committed baseline at ${BASELINE} — run --collect on a known-good tree first" >&2
    exit 2
fi

grep -v '^#' "$BASELINE" | grep -v '^$' | LC_ALL=C sort -u > /tmp/.cb_baseline

regressions=$(LC_ALL=C comm -13 /tmp/.cb_baseline /tmp/.cb_current_net)
fixes=$(LC_ALL=C comm -23 /tmp/.cb_baseline /tmp/.cb_current_net)

echo "compliance-baseline: ${SUITE}"
echo "  baseline failing : $(wc -l < /tmp/.cb_baseline | tr -d ' ')"
echo "  current  failing : $(wc -l < /tmp/.cb_current_net | tr -d ' ')"
echo "  quarantined      : $(wc -l < /tmp/.cb_quarantine | tr -d ' ')"

# Split the apparent fixes: a case that is no longer executed did not get fixed, it disappeared.
# Reporting both under "FIXED" is how real failures get dropped from the baseline (#24726).
#
# Splitting FIXED from DROPPED needs only THIS run's executed set. The executed BASELINE is a
# separate question (what is newly executed), so the two are gated independently — conflating them
# once printed "executed, passing now" over a list that had never been checked for execution.
dropped=""
real_fixes="$fixes"
can_split=0
if [ -s /tmp/.cb_executed ]; then
    can_split=1
    printf '%s\n' $fixes | grep -v '^$' | LC_ALL=C sort -u > /tmp/.cb_fixes
    dropped=$(LC_ALL=C comm -23 /tmp/.cb_fixes /tmp/.cb_executed)
    real_fixes=$(LC_ALL=C comm -12 /tmp/.cb_fixes /tmp/.cb_executed)
fi

if [ -n "$real_fixes" ]; then
    echo
    if [ "$can_split" -eq 1 ]; then
        echo "FIXED (in baseline, executed, passing now) — regenerate the baseline once verified:"
    else
        echo "FIXED OR DROPPED (in baseline, not failing now) — execution NOT verified:"
    fi
    printf '  %s\n' $real_fixes
    if [ "$can_split" -eq 0 ]; then
        echo
        echo "  No .executed shard files in ${SHARD_DIR}, so this list may contain cases that were"
        echo "  never run rather than fixed. Do not regenerate the baseline from it."
    fi
fi

if [ -n "$dropped" ]; then
    echo
    echo "DROPPED (in baseline, NOT EXECUTED in this run) — these were not fixed:"
    printf '  %s\n' $dropped
    echo
    echo "  The provider stopped yielding them. VMTest gates cases on CompilerVersion::supports*(),"
    echo "  so a profile change silently removes cases from the corpus. Regenerating the baseline"
    echo "  now would drop them from tracking permanently — decide deliberately, per case."
fi

if [ "$can_split" -eq 1 ] && [ -f "$EXECUTED_BASELINE" ]; then
    grep -v '^#' "$EXECUTED_BASELINE" | grep -v '^$' | LC_ALL=C sort -u > /tmp/.cb_exec_baseline
    newly_run=$(LC_ALL=C comm -13 /tmp/.cb_exec_baseline /tmp/.cb_executed)
    if [ -n "$newly_run" ]; then
        echo
        echo "NEWLY EXECUTED (not in the executed baseline) — $(printf '%s\n' $newly_run | wc -l | tr -d ' ') case(s)."
        echo "  A profile change enabled them. Any that fail appear as regressions below but are"
        echo "  new coverage, not new breakage — attribute them before believing the regression."
    fi
elif [ "$can_split" -eq 1 ]; then
    echo
    echo "NOTE: no ${EXECUTED_BASELINE} — FIXED/DROPPED is split, but newly-executed cases cannot"
    echo "  be flagged. Run --collect to establish it."
fi

if [ -n "$regressions" ]; then
    echo
    echo "REGRESSIONS (failing now, not in baseline):" >&2
    printf '  %s\n' $regressions >&2
    echo >&2
    echo "Re-verify each individually before believing it — several compliance cases are" >&2
    echo "order-dependent, and a case that flips between runs of the same commit belongs in" >&2
    echo "${QUARANTINE} with an issue, not in the baseline." >&2
    exit 1
fi

echo
echo "no regressions"
exit 0
