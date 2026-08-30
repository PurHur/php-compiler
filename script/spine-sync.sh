#!/usr/bin/env bash
# One-command spine maintenance (#1922, #1945, #2202, #1834 — full chain).
#
# When inventory grows, the manual chain is: discover missing files → append
# require_once entries → regen inventory/profile → recount → rewrite spine
# footnotes in six docs + the test assertion → refresh gen-0 sidecars → verify.
# Fleet agents run this several times a day by hand (~1 h each). This script
# does the whole chain:
#
#   ./script/spine-sync.sh                  # full chain incl. sidecar refresh
#   ./script/spine-sync.sh --no-link        # skip the sidecar relink (stamp-only PRs)
#   ./script/spine-sync.sh --footnotes-only # recount + rewrite footnote pairs only
#                                           # (no bundle edit, no regen, no sidecar —
#                                           # what ci-fast auto-heal runs, #1802)
#
# Requires the pinned env for the sidecar step (LLVM 9).
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$(pwd)"
SPINE="test/selfhost/compiler_lib_spine_smoke/main.php"
PHP_BIN="${PHP_BIN:-php}"

NO_LINK=0
FOOTNOTES_ONLY=0
for arg in "$@"; do
  [[ "$arg" == "--no-link" ]] && NO_LINK=1
  [[ "$arg" == "--footnotes-only" ]] && { FOOTNOTES_ONLY=1; NO_LINK=1; }
done

if [[ "$FOOTNOTES_ONLY" == "1" ]]; then
  echo "==> spine-sync 1/6+2/6: SKIP bundle discovery/regen (--footnotes-only)"
fi
if [[ "$FOOTNOTES_ONLY" != "1" ]]; then
echo "==> spine-sync 1/6: discover missing inventory files"
# Diff Phase A inventory against LITERAL spine requires (the M2 counter's own
# logic) — the coverage checker tolerates renamed-path shadows (#2202 gap).
MISSING_LIST=/tmp/spine-sync-missing.txt
"$PHP_BIN" -r '
require "script/bootstrap-lib.php";
require "script/bootstrap-spine-deferred-lib.php";
$root = getcwd();
$report = bootstrapCollectInventoryReport($root);
$inv = array_keys($report["files"] ?? []);
$paths = [];
foreach (file($root."/'"$SPINE"'", FILE_IGNORE_NEW_LINES) as $line) {
    if (preg_match("#require_once __DIR__\\.\x27/\\.\\./\\.\\./\\.\\./([^\x27]+)\x27;#", $line, $m)) {
        $paths[$m[1]] = true;
    }
}
// Deferred paths (SSOT bootstrap-spine-deferred-lib.php) are covered by the
// deferred footnote, not a bundle require — do not re-add them.
foreach (bootstrap_spine_native_link_deferred() as $rel) {
    $paths[$rel] = true;
}
foreach ($inv as $rel) {
    if (!isset($paths[$rel])) {
        echo $rel, "\n";
    }
}' > "$MISSING_LIST"

added=0
if [[ -s "$MISSING_LIST" ]]; then
  while IFS= read -r rel; do
    rel="$(echo "$rel" | xargs)"
    [[ -z "$rel" ]] && continue
    if ! grep -qF "/$rel'" "$SPINE"; then
      # Insert before the bundle-OK echo so the entry lands inside the bundle body.
      line="require_once __DIR__.'/../../../${rel}';"
      awk -v ins="$line" '!done && /compiler_lib_spine_smoke bundle OK/ { print ins; done=1 } { print }' \
        "$SPINE" > "$SPINE.tmp" && mv "$SPINE.tmp" "$SPINE"
      ((added++)) || true
    fi
  done < "$MISSING_LIST"
fi
echo "    added ${added} spine entries"

# Merge-race PRs can append the same require_once twice (#19033, #19111). Keep first
# occurrence only so AOT spine-smoke does not double-load units.
deduped=$("$PHP_BIN" -r '
$path = $argv[1];
$lines = file($path);
$seen = [];
$out = [];
foreach ($lines as $line) {
    if (preg_match("#^require_once __DIR__\\.\x27/#", $line)) {
        $key = rtrim($line);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
    }
    $out[] = $line;
}
$before = count($lines);
$after = count($out);
if ($after < $before) {
    file_put_contents($path, implode("", $out));
}
echo $before - $after;
' "$SPINE")
if [[ "${deduped:-0}" -gt 0 ]]; then
  echo "    deduped ${deduped} duplicate require_once lines"
fi

echo "==> spine-sync 2/6: regenerate inventory + profile"
"$PHP_BIN" script/bootstrap-inventory.php >/dev/null
"$PHP_BIN" script/bootstrap-profile.php >/dev/null
fi

echo "==> spine-sync 3/6: recount and rewrite footnotes"
read -r NEW_SPINE NEW_INV < <("$PHP_BIN" script/bootstrap-spine-count.php --json \
  | "$PHP_BIN" -r '$j = json_decode(stream_get_contents(STDIN), true); echo $j["spine"], " ", $j["inventory"], "\n";')
# Collect every distinct stale spine-ratio pair across the footnote set — not
# only README's "self-host spine N/N". ci-fast's --footnotes-only heal used to
# rewrite README's pair alone, leaving docs that still said N/M (e.g. 8003/8006
# while README was 8003/8003) red (#35784 / #1802). Historic pairs (6702/6703)
# and vendor-prelink (7410/7412) are left alone: we only touch pairs tied to
# the README seed spine or the live inventory total.
FOOTNOTE_FILES=(README.md docs/pages/development-status.md docs/self-host-target.md
  docs/bootstrap-selfhost.md docs/roadmap-wave3.md docs/pages/index.html
  docs/bootstrap-generations.md docs/pages/missing-implementation.html)
README_PAIR="$(grep -oE 'self-host spine \*\*[0-9]+\*\* / \*\*[0-9]+\*\*' README.md | head -1 \
  | grep -oE '[0-9]+\*\* / \*\*[0-9]+' | sed 's/\*\*//g' || true)"
README_SPINE="$(echo "${README_PAIR%% /*}" | xargs)"
README_INV="$(echo "${README_PAIR##*/ }" | xargs)"
mapfile -t OLD_PAIRS < <(
  {
    [[ -n "${README_PAIR}" ]] && echo "${README_SPINE}/${README_INV}"
    # Same-generation N/M when README was N/N but docs kept inventory = NEW_INV
    [[ -n "${README_SPINE}" ]] && echo "${README_SPINE}/${NEW_INV}"
    # Any bold pair whose inventory side is the live total (stale spine count)
    grep -hEo "\*\*[0-9]{2,4}\*\* / \*\*${NEW_INV}\*\*|\*\*[0-9]{2,4}/${NEW_INV}\*\*" \
      "${FOOTNOTE_FILES[@]}" 2>/dev/null \
      | sed -E 's/\*\*//g; s| / |/|' || true
    # Any bold pair sharing the README spine numerator
    if [[ -n "${README_SPINE}" ]]; then
      grep -hEo "\*\*${README_SPINE}\*\* / \*\*[0-9]{2,4}\*\*|\*\*${README_SPINE}/[0-9]{2,4}\*\*" \
        "${FOOTNOTE_FILES[@]}" 2>/dev/null \
        | sed -E 's/\*\*//g; s| / |/|' || true
    fi
  } | awk -F/ -v ns="$NEW_SPINE" -v ni="$NEW_INV" '
    NF==2 && $1+0==$1 && $2+0==$2 {
      if ($1 != ns || $2 != ni) print $1 "/" $2
    }' | sort -u
)
if [[ ${#OLD_PAIRS[@]} -eq 0 ]]; then
  echo "    (no stale pairs; already ${NEW_SPINE}/${NEW_INV})"
else
  echo "    stale -> ${NEW_SPINE}/${NEW_INV}: ${OLD_PAIRS[*]}"
fi
for OLD_PAIR in "${OLD_PAIRS[@]+"${OLD_PAIRS[@]}"}"; do
  [[ -z "$OLD_PAIR" ]] && continue
  OLD_SPINE="${OLD_PAIR%%/*}"
  OLD_INV="${OLD_PAIR##*/}"
  sed -i \
    -e "s/\*\*${OLD_SPINE}\*\* \/ \*\*${OLD_INV}\*\*/**${NEW_SPINE}** \/ **${NEW_INV}**/g" \
    -e "s/\*\*${OLD_SPINE}\/${OLD_INV}\*\*/**${NEW_SPINE}\/${NEW_INV}**/g" \
    -e "s/${OLD_SPINE} of ${OLD_INV}/${NEW_SPINE} of ${NEW_INV}/g" \
    -e "s/${OLD_SPINE} \/ ${OLD_INV}/${NEW_SPINE} \/ ${NEW_INV}/g" \
    -e "s/${OLD_SPINE}\/${OLD_INV}/${NEW_SPINE}\/${NEW_INV}/g" \
    "${FOOTNOTE_FILES[@]}"
  sed -i \
    -e "s/assertSame(${OLD_SPINE}, \$count/assertSame(${NEW_SPINE}, \$count/" \
    -e "s|Spine ratio ${OLD_SPINE}/${OLD_INV}|Spine ratio ${NEW_SPINE}/${NEW_INV}|" \
    test/unit/BootstrapSelfhostBundleTest.php
done
# assertSame may still lag when docs were already NEW but the test was not
# (or when no **N**/**M** pair matched the grep). Force the canonical count.
if ! grep -q "assertSame(${NEW_SPINE}, \$count" test/unit/BootstrapSelfhostBundleTest.php; then
  sed -i -E "s/assertSame\([0-9]+, \$count/assertSame(${NEW_SPINE}, \$count/" \
    test/unit/BootstrapSelfhostBundleTest.php
  echo "    assertSame -> ${NEW_SPINE}"
fi

echo "==> spine-sync 4/6: verify counts + coverage + deferred + roadmap"
"$PHP_BIN" script/check-selfhost-spine-coverage-sync.php | tail -1
"$PHP_BIN" script/check-selfhost-spine-count-sync.php | tail -1
"$PHP_BIN" script/check-selfhost-spine-deferred-sync.php | tail -1
if ! "$PHP_BIN" script/check-wave3-roadmap-sync.php | tail -1; then
  echo "spine-sync: a footnote file carries an older pair this tool cannot" >&2
  echo "  blanket-replace (historic log rows share the pattern). Fix the file" >&2
  echo "  named above by hand (old-pair → ${NEW_SPINE}/${NEW_INV}) and rerun." >&2
  exit 1
fi

if [[ "$NO_LINK" == "1" ]]; then
  echo "==> spine-sync 5/6: SKIP sidecar refresh (--no-link)"
else
  echo "==> spine-sync 5/6: gen-0 sidecar refresh (full spine link — minutes)"
  make bootstrap-gen0-refresh-sidecar
fi

echo "==> spine-sync 6/6: sidecar stamp check"
"$PHP_BIN" script/check-selfhost-spine-sidecar-sync.php | tail -1
echo "spine-sync: done — commit spine, docs, prelinked/bootstrap-gen0 together"
