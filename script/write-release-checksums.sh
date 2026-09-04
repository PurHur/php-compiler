#!/usr/bin/env bash
#
# Write SHA256SUMS for release artifacts (#36399).
#
# Usage:
#   script/write-release-checksums.sh OUT_DIR file [file ...]
#   script/write-release-checksums.sh OUT_DIR --glob 'build/phpc-*.tar.*'
#
# Writes OUT_DIR/SHA256SUMS (GNU sha256sum format, sorted by basename).
# Does not sign; pair with minisign/cosign in a later #36399 slice.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT" || exit 1

if [[ "$#" -lt 2 ]]; then
  echo "usage: $0 OUT_DIR file [file ...] | $0 OUT_DIR --glob PATTERN" >&2
  exit 2
fi

OUT_DIR=$1
shift
mkdir -p "$OUT_DIR"

FILES=()
if [[ "${1:-}" == "--glob" ]]; then
  shift
  PATTERN=${1:-}
  if [[ -z "$PATTERN" ]]; then
    echo "write-release-checksums: --glob needs a pattern" >&2
    exit 2
  fi
  # shellcheck disable=SC2086
  for f in $PATTERN; do
    [[ -f "$f" ]] && FILES+=("$f")
  done
else
  for f in "$@"; do
    if [[ ! -f "$f" ]]; then
      echo "write-release-checksums: missing file $f" >&2
      exit 1
    fi
    FILES+=("$f")
  done
fi

if [[ "${#FILES[@]}" -eq 0 ]]; then
  echo "write-release-checksums: no files to hash" >&2
  exit 1
fi

# Sort by basename for stable SHA256SUMS across pack runs.
mapfile -t SORTED < <(printf '%s\n' "${FILES[@]}" | awk -F/ '{print $NF "\t" $0}' | sort -t $'\t' -k1,1 | cut -f2-)

SUMS="${OUT_DIR}/SHA256SUMS"
: > "$SUMS"
for f in "${SORTED[@]}"; do
  # Emit "hash  basename" so verify works after extract without full paths.
  hash=$(sha256sum "$f" | awk '{print $1}')
  base=$(basename "$f")
  printf '%s  %s\n' "$hash" "$base" >> "$SUMS"
done

echo "write-release-checksums: wrote ${SUMS} (${#SORTED[@]} file(s))"
# Soft check: every line has 64 hex chars + two spaces + name.
while IFS= read -r line; do
  if ! [[ "$line" =~ ^[0-9a-f]{64}\ \  ]]; then
    echo "write-release-checksums: malformed line: $line" >&2
    exit 1
  fi
done < "$SUMS"
exit 0
