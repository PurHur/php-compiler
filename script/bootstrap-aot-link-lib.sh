#!/usr/bin/env bash
# Bootstrap AOT link + execute gate for namespaced lib/ units (issue #540 Phase D).
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
  echo "bootstrap-aot-link-lib: LLVM 9 not found (skip)" >&2
  exit 2
fi

profile="${ROOT}/docs/bootstrap-profile.json"
if [[ ! -f "$profile" ]]; then
  echo "bootstrap-aot-link-lib: missing ${profile}; run: make bootstrap-profile" >&2
  exit 1
fi

compile_bin="${ROOT}/bin/compile.php"
if [[ ! -f "$compile_bin" ]]; then
  echo "bootstrap-aot-link-lib: missing ${compile_bin}" >&2
  exit 1
fi

mapfile -t targets < <("$PHP_BIN" "${PHP_OPTS[@]}" -r '
$profile = json_decode((string) file_get_contents($argv[1]), true);
if (!is_array($profile) || !isset($profile["aot_link_lib_targets"]) || !is_array($profile["aot_link_lib_targets"])) {
    fwrite(STDERR, "Invalid bootstrap profile: missing aot_link_lib_targets\n");
    exit(1);
}
foreach ($profile["aot_link_lib_targets"] as $rel) {
    if (is_string($rel)) {
        echo $rel, "\n";
    }
}
' "$profile")
if ((${#targets[@]} == 0)); then
  echo "bootstrap-aot-link-lib: no targets" >&2
  exit 1
fi

out_dir="${ROOT}/build/bootstrap-aot-lib"
mkdir -p "$out_dir"
failures=()

for rel in "${targets[@]}"; do
  source="${ROOT}/${rel}"
  base="$(basename "$(dirname "$rel")")_$(basename "$rel" .php)"
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
  echo "bootstrap-aot-link-lib failed:" >&2
  printf '%s\n\n' "${failures[@]}" >&2
  exit 1
fi

echo "bootstrap-aot-link-lib: ${#targets[@]} target(s) OK"
exit 0
