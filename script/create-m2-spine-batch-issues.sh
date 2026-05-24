#!/usr/bin/env bash
# Create M2 spine growth batch issues from script/create-m2-spine-batch-issues.php
set -euo pipefail
cd "$(dirname "$0")/.."

JSON="$(mktemp)"
trap 'rm -f "$JSON"' EXIT

php script/create-m2-spine-batch-issues.php --json >"$JSON"

if ! php script/create-m2-spine-batch-issues.php >/dev/null 2>&1; then
  echo "create-m2-spine-batch-issues: coverage check failed" >&2
  exit 1
fi

LABELS="area:compiler,enhancement"

echo "Creating umbrella issue..."
UMBRELLA_URL="$(php -r '
$d = json_decode(file_get_contents($argv[1]), true);
echo $d["umbrella"]["body"];
' "$JSON" | gh issue create \
  --title "M2 spine growth: batch tracker (compiler_lib_spine_smoke → full inventory)" \
  --label "$LABELS" \
  --body-file -)"
UMBRELLA_NUM="${UMBRELLA_URL##*/}"
echo "Umbrella: #$UMBRELLA_NUM"

CHILD_ISSUES=()
BATCH_COUNT="$(php -r 'echo count(json_decode(file_get_contents($argv[1]), true)["batches"]);' "$JSON")"

for ((i = 0; i < BATCH_COUNT; i++)); do
  TITLE="$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo $d["batches"][$argv[2]]["title"];' "$JSON" "$i")"
  BODY="$(php -r '$d=json_decode(file_get_contents($argv[1]),true);
$b=$d["batches"][$argv[2]]["body"];
$b=str_replace("(linked after creation)", "#'.$UMBRELLA_NUM.'", $b);
echo $b;' "$JSON" "$i")"
  URL="$(printf '%s' "$BODY" | gh issue create --title "$TITLE" --label "$LABELS" --body-file -)"
  NUM="${URL##*/}"
  CHILD_ISSUES+=("$NUM")
  echo "  #$NUM — $TITLE"
done

# Build umbrella update with child table
TABLE="$(printf '| # | Batch |\n|---|-------|\n')"
for ((i = 0; i < BATCH_COUNT; i++)); do
  TITLE="$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo $d["batches"][$argv[2]]["title"];' "$JSON" "$i")"
  NUM="${CHILD_ISSUES[$i]}"
  TABLE+="| #${NUM} | ${TITLE} |\n"
done

STATS="$(php -r '
$d=json_decode(file_get_contents($argv[1]),true);
echo "**Inventory:** {$d["inventory_total"]} files · **Spine today:** {$d["spine_total"]} units · **Batched gap:** {$d["missing_total"]} files · **Issues:** {$argv[2]}\n";
' "$JSON" "$BATCH_COUNT")"

gh issue comment "$UMBRELLA_NUM" --body "$(cat <<EOF
## Child issues created

${STATS}

${TABLE}

**Parent tracker:** #1056

Regenerate batches: \`php script/create-m2-spine-batch-issues.php\`
EOF
)"

gh issue comment 1056 --body "M2 spine batch tracker: #$UMBRELLA_NUM (${BATCH_COUNT} child issues for ~387 missing inventory files)."

echo ""
echo "Done: umbrella #$UMBRELLA_NUM with ${BATCH_COUNT} child issues."
echo "Verify: php script/create-m2-spine-batch-issues.php"
