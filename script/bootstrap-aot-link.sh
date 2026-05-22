#!/usr/bin/env bash
# Bootstrap AOT link + execute gate (issue #512 Phase C).
# Compiles each docs/bootstrap-profile.json aot_link_targets entry (fallback: aot_lint_targets) to
# build/bootstrap-aot/<basename> and compares stdout to Zend PHP.
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
ci_apply_llvm_memory_env

verbose=0
for arg in "$@"; do
  if [[ "$arg" == "--verbose" ]]; then
    verbose=1
  fi
done

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-aot-link: LLVM 9 not found (skip)" >&2
  exit 2
fi

profile="${ROOT}/docs/bootstrap-profile.json"
if [[ ! -f "$profile" ]]; then
  echo "bootstrap-aot-link: missing ${profile}; run: make bootstrap-profile" >&2
  exit 1
fi

compile_bin="${ROOT}/bin/compile.php"
if [[ ! -f "$compile_bin" ]]; then
  echo "bootstrap-aot-link: missing ${compile_bin}" >&2
  exit 1
fi

mapfile -t targets < <("$PHP_BIN" "${PHP_OPTS[@]}" -r '
$profile = json_decode((string) file_get_contents($argv[1]), true);
if (!is_array($profile) || !isset($profile["aot_lint_targets"]) || !is_array($profile["aot_lint_targets"])) {
    fwrite(STDERR, "Invalid bootstrap profile\n");
    exit(1);
}
$targets = $profile["aot_link_targets"] ?? $profile["aot_lint_targets"];
if (!is_array($targets)) {
    fwrite(STDERR, "Invalid bootstrap profile: missing aot_link_targets / aot_lint_targets\n");
    exit(1);
}
foreach ($targets as $rel) {
    if (is_string($rel)) {
        echo $rel, "\n";
    }
}
' "$profile")

out_dir="${ROOT}/build/bootstrap-aot"
mkdir -p "$out_dir"
failures=()

for rel in "${targets[@]}"; do
  source="${ROOT}/${rel}"
  base="$(basename "$rel" .php)"
  binary="${out_dir}/${base}"
  if [[ ! -f "$source" ]]; then
    failures+=("${rel}: file not found")
    continue
  fi
  if ! "$PHP_BIN" "${PHP_OPTS[@]}" "$compile_bin" -o "$binary" "$source" >&2; then
    failures+=("${rel}: compile failed")
    continue
  fi
  if [[ ! -x "$binary" ]]; then
    failures+=("${rel}: binary not executable (${binary})")
    continue
  fi
  expected="$("$PHP_BIN" "${PHP_OPTS[@]}" "$source" 2>/dev/null || true)"
  actual="$("$binary" 2>/dev/null || true)"
  if [[ "$expected" != "$actual" ]]; then
    failures+=("${rel}: output mismatch
expected:
${expected}
actual:
${actual}")
    continue
  fi
  if [[ "$verbose" -eq 1 ]]; then
    echo "OK ${rel}"
  fi
done

if ((${#failures[@]} > 0)); then
  echo "bootstrap-aot-link failed:" >&2
  printf '%s\n\n' "${failures[@]}" >&2
  exit 1
fi

echo "bootstrap-aot-link: ${#targets[@]} target(s) OK"
exit 0
