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

BEFORE_DIR=""
AFTER_DIR=""

for arg in "$@"; do
    case "$arg" in
        --suite=*)  SUITE="${arg#*=}" ;;
        --collect)  MODE="collect" ;;
        --diff)     MODE="diff" ;;
        --compare)  MODE="compare" ;;
        --before=*) BEFORE_DIR="${arg#*=}" ;;
        --after=*)  AFTER_DIR="${arg#*=}" ;;
        *) echo "unknown argument: $arg" >&2; exit 2 ;;
    esac
done

if [ -z "$MODE" ]; then
    echo "usage: $0 (--collect|--diff|--compare --before=DIR --after=DIR) [--suite=VMTest]" >&2
    exit 2
fi

if [ "$MODE" = "compare" ] && { [ -z "$BEFORE_DIR" ] || [ -z "$AFTER_DIR" ]; }; then
    echo "compliance-baseline: --compare needs both --before=DIR and --after=DIR" >&2
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
    local dir="${1:-$SHARD_DIR}"
    local files
    files=$(find "$dir" -name "${SUITE}-*-of-*.failed" 2>/dev/null | LC_ALL=C sort)
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
    local dir="${1:-$SHARD_DIR}"
    local files
    files=$(find "$dir" -name "${SUITE}-*-of-*.executed" 2>/dev/null | LC_ALL=C sort)
    if [ -z "$files" ]; then
        return 1
    fi
    # shellcheck disable=SC2086
    cat $files 2>/dev/null \
        | sed -e 's/^.*data set "//' -e 's/"$//' \
        | grep -v '^$' \
        | LC_ALL=C sort -u
}

# Cases the runner SKIPPED (--SKIPIF-- said so, #24888). Tracked separately from "not yielded at
# all": a skipped case declares itself inapplicable to this host/profile, while an unyielded case
# was gated out by VMTest::providePHPTests(). Both must be distinguishable from FIXED, because
# PHPUnit emits testStarted for a skipped case too.
current_skipped() {
    local dir="${1:-$SHARD_DIR}"
    local files
    files=$(find "$dir" -name "${SUITE}-*-of-*.skipped" 2>/dev/null | LC_ALL=C sort)
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
    local dir="${1:-$SHARD_DIR}"
    local widths
    widths=$(find "$dir" -name "${SUITE}-*-of-*.failed" 2>/dev/null \
        | sed -E 's/.*-of-([0-9]+)\.failed/\1/' | LC_ALL=C sort -u)
    local distinct
    distinct=$(printf '%s\n' "$widths" | grep -c '^[0-9]' || true)
    if [ "${distinct:-0}" -gt 1 ]; then
        echo "MIXED $(printf '%s' "$widths" | tr '\n' ',')"
        return
    fi
    local n
    n=$(find "$dir" -name "${SUITE}-*-of-*.failed" 2>/dev/null | wc -l | tr -d ' ')
    echo "${n:-0} ${widths:-0}"
}

# Every guard that makes a result set trustworthy, applied to ONE directory. Factored out so both
# sides of a --compare get validated identically — a merge-base run with a timed-out shard would
# otherwise make the PR look like it fixed everything that shard never reached.
validate_shard_dir() {
    local dir="$1"
    local label="$2"

    if ! current_failing "$dir" > /dev/null 2>&1; then
        echo "compliance-baseline: no ${SUITE} shard results under ${dir}/ (${label})" >&2
        echo "  run script/shard-compliance.sh --suite=${SUITE} --shards=N --shard=i for every i first" >&2
        exit 2
    fi

    local have want
    read -r have want <<< "$(shard_coverage "$dir")"
    if [ "$have" = "MIXED" ]; then
        echo "compliance-baseline: MIXED shard widths present (${want}) under ${dir}/ (${label})" >&2
        echo "  Results from different --shards=N runs cannot be unioned. Remove the stale ones." >&2
        exit 2
    fi

    local timeouts
    timeouts=$(find "$dir" -name "${SUITE}-*-of-*.timeout" 2>/dev/null | LC_ALL=C sort)
    if [ -n "$timeouts" ]; then
        echo "compliance-baseline: TIMED-OUT SHARDS in ${label} — the result set is incomplete:" >&2
        for t in $timeouts; do
            echo "  $(basename "$t"): $(grep '^stalled_on=' "$t" 2>/dev/null | cut -d= -f2-)" >&2
        done
        echo "  A timed-out shard writes a .failed file that LOOKS complete; every case it never" >&2
        echo "  reached would read as newly-passing. Fix or disable the hanging case, then re-run." >&2
        exit 2
    fi

    if [ "$want" -gt 0 ] && [ "$have" -lt "$want" ]; then
        # A partial set looks exactly like "these cases got fixed". Refuse it.
        echo "compliance-baseline: INCOMPLETE ${label} — ${have} of ${want} shards have results." >&2
        echo "  Diffing a partial run reports every unrun case as newly-passing. Finish the run first." >&2
        exit 2
    fi

    SHARD_WIDTH="$want"
}

if [ "$MODE" != "compare" ]; then
    validate_shard_dir "$SHARD_DIR" "shard results"
    want="$SHARD_WIDTH"
    current_failing > /tmp/.cb_current
fi

quarantined > /tmp/.cb_quarantine || true

# --------------------------------------------------------------------------------------------
# compare: two shard directories from the SAME CI run — merge-base vs PR head.
#
# This is what the committed baseline could never be. master merges ~12 commits/hour, so a
# baseline file is stale the moment it is written: a diff against it conflates what the PR did
# with everything that landed since it was collected. That conflation is not hypothetical — it
# produced 23 phantom regressions (#24726), a quarantine of 26 entries of which 25 were not flaky,
# and 6 real failures hidden as a result.
#
# Comparing two runs of the same CI job removes the confound entirely: same image, same corpus,
# same host libraries, differing only by the PR's own commits.
# --------------------------------------------------------------------------------------------
if [ "$MODE" = "compare" ]; then
    validate_shard_dir "$BEFORE_DIR" "merge-base"
    validate_shard_dir "$AFTER_DIR" "head"

    current_failing  "$BEFORE_DIR" > /tmp/.cb_bfail
    current_failing  "$AFTER_DIR"  > /tmp/.cb_afail
    current_executed "$BEFORE_DIR" > /tmp/.cb_bran 2>/dev/null || : > /tmp/.cb_bran
    current_executed "$AFTER_DIR"  > /tmp/.cb_aran 2>/dev/null || : > /tmp/.cb_aran
    current_skipped  "$AFTER_DIR"  > /tmp/.cb_askip 2>/dev/null || : > /tmp/.cb_askip

    LC_ALL=C comm -23 /tmp/.cb_bfail /tmp/.cb_quarantine > /tmp/.cb_bfail_net
    LC_ALL=C comm -23 /tmp/.cb_afail /tmp/.cb_quarantine > /tmp/.cb_afail_net

    regressions=$(LC_ALL=C comm -13 /tmp/.cb_bfail_net /tmp/.cb_afail_net)
    resolved=$(LC_ALL=C comm -23 /tmp/.cb_bfail_net /tmp/.cb_afail_net)

    echo "compliance-baseline: ${SUITE} — merge-base vs head"
    echo "  merge-base failing : $(wc -l < /tmp/.cb_bfail_net | tr -d ' ')"
    echo "  head       failing : $(wc -l < /tmp/.cb_afail_net | tr -d ' ')"
    echo "  merge-base ran     : $(wc -l < /tmp/.cb_bran | tr -d ' ')"
    echo "  head       ran     : $(wc -l < /tmp/.cb_aran | tr -d ' ')"
    echo "  quarantined        : $(wc -l < /tmp/.cb_quarantine | tr -d ' ')"

    # Same four-way split as --diff: a case that stopped failing may have been fixed, skipped, or
    # dropped from the corpus, and only the first is good news.
    if [ -n "$resolved" ]; then
        printf '%s\n' $resolved | grep -v '^$' | LC_ALL=C sort -u > /tmp/.cb_resolved
        r_fixed=$(LC_ALL=C comm -12 /tmp/.cb_resolved /tmp/.cb_aran)
        LC_ALL=C comm -23 /tmp/.cb_resolved /tmp/.cb_aran > /tmp/.cb_rnotrun
        r_skipped=$(LC_ALL=C comm -12 /tmp/.cb_rnotrun /tmp/.cb_askip)
        r_dropped=$(LC_ALL=C comm -23 /tmp/.cb_rnotrun /tmp/.cb_askip)

        if [ -n "$r_fixed" ]; then
            echo
            echo "FIXED by this change (failed at merge-base, ran and passes at head):"
            printf '  %s\n' $r_fixed
        fi
        if [ -n "$r_skipped" ]; then
            echo
            echo "SKIPPED at head (failed at merge-base, --SKIPIF-- skipped it now) — not fixed:"
            printf '  %s\n' $r_skipped
        fi
        if [ -n "$r_dropped" ]; then
            echo
            echo "DROPPED at head (failed at merge-base, NEITHER run NOR skipped) — not fixed:"
            printf '  %s\n' $r_dropped
            echo "  This change removed them from the corpus. That is a coverage loss."
        fi
    fi

    newly_run=$(LC_ALL=C comm -13 /tmp/.cb_bran /tmp/.cb_aran)
    if [ -n "$newly_run" ]; then
        echo
        echo "NEWLY EXECUTED at head — $(printf '%s\n' $newly_run | wc -l | tr -d ' ') case(s) the merge-base did not run."
        echo "  Any of these appearing under REGRESSIONS are NEW COVERAGE, not new breakage."
    fi

    if [ -n "$regressions" ]; then
        printf '%s\n' $regressions | grep -v '^$' | LC_ALL=C sort -u > /tmp/.cb_regr
        # Split the regressions the same way, so "this case only started running here" is visible
        # rather than being read as breakage.
        new_cov=$(LC_ALL=C comm -12 /tmp/.cb_regr <(printf '%s\n' $newly_run | grep -v '^$' | LC_ALL=C sort -u))
        genuine=$(LC_ALL=C comm -23 /tmp/.cb_regr <(printf '%s\n' $newly_run | grep -v '^$' | LC_ALL=C sort -u))

        if [ -n "$genuine" ]; then
            echo >&2
            echo "REGRESSIONS introduced by this change (ran at merge-base, fails at head):" >&2
            printf '  %s\n' $genuine >&2
        fi
        if [ -n "$new_cov" ]; then
            echo >&2
            echo "FAILING BUT NEWLY EXECUTED (did not run at merge-base) — new coverage, not new breakage:" >&2
            printf '  %s\n' $new_cov >&2
        fi
        if [ -n "$genuine" ]; then
            echo >&2
            echo "Both sides came from the same CI run, so host differences and commit drift are" >&2
            echo "excluded by construction. A name above is this change's doing." >&2
            exit 1
        fi
        echo
        echo "no regressions (only newly-executed cases fail)"
        exit 0
    fi

    echo
    echo "no regressions"
    exit 0
fi

LC_ALL=C comm -23 /tmp/.cb_current /tmp/.cb_quarantine > /tmp/.cb_current_net

current_executed > /tmp/.cb_executed 2>/dev/null || : > /tmp/.cb_executed
current_skipped > /tmp/.cb_skipped 2>/dev/null || : > /tmp/.cb_skipped

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
skipped_now=""
real_fixes="$fixes"
can_split=0
if [ -s /tmp/.cb_executed ]; then
    can_split=1
    printf '%s\n' $fixes | grep -v '^$' | LC_ALL=C sort -u > /tmp/.cb_fixes
    # Three-way, in precedence order: ran-and-passes (FIXED), was skipped (SKIPPED), neither
    # (DROPPED — the provider never yielded it).
    real_fixes=$(LC_ALL=C comm -12 /tmp/.cb_fixes /tmp/.cb_executed)
    LC_ALL=C comm -23 /tmp/.cb_fixes /tmp/.cb_executed > /tmp/.cb_notrun
    skipped_now=$(LC_ALL=C comm -12 /tmp/.cb_notrun /tmp/.cb_skipped)
    dropped=$(LC_ALL=C comm -23 /tmp/.cb_notrun /tmp/.cb_skipped)
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

if [ -n "$skipped_now" ]; then
    echo
    echo "SKIPPED (in baseline, --SKIPIF-- skipped them this run) — these were not fixed:"
    printf '  %s\n' $skipped_now
    echo
    echo "  The case declared itself inapplicable to this host/profile (#24888). That is usually"
    echo "  correct and they may leave the baseline — but it is a coverage LOSS, not a repair, and"
    echo "  a guard that started firing wrongly would look identical. Check why each one skips."
fi

if [ -n "$dropped" ]; then
    echo
    echo "DROPPED (in baseline, NEITHER run NOR skipped) — these were not fixed:"
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
