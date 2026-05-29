#!/usr/bin/env bash
# Install committed gen-0 native compile driver into build/ (#3053).
set -euo pipefail

_bootstrap_gen0_seed_ensure_invoke_helpers() {
  if declare -F bootstrap_is_inventory_bin_compile_argv_driver >/dev/null 2>&1; then
    return 0
  fi
  local root="${ROOT:-$(cd "$(dirname "$0")/.." && pwd)}"
  # shellcheck source=bootstrap-resolve-compile-invoke.sh
  source "${root}/script/bootstrap-resolve-compile-invoke.sh"
}

bootstrap_gen0_seed_manifest() {
  local root="${1:-}"
  echo "${root}/prelinked/bootstrap-gen0/manifest.json"
}

bootstrap_gen0_seed_verify() {
  local root="${1:-}"
  local manifest
  manifest="$(bootstrap_gen0_seed_manifest "${root}")"
  local driver="${root}/prelinked/bootstrap-gen0/bin-compile-aot"
  local blob="${root}/prelinked/bootstrap-gen0/.m3_bin_compile_aot_blob"
  if [[ ! -f "${manifest}" || ! -f "${driver}" || ! -f "${blob}" ]]; then
    return 1
  fi
  local expect_bytes expect_sha
  expect_bytes="$(php -r '
    $m = json_decode((string) file_get_contents($argv[1]), true);
    echo (int) ($m["artifacts"]["bin-compile-aot"]["bytes"] ?? 0);
  ' "${manifest}")"
  expect_sha="$(php -r '
    $m = json_decode((string) file_get_contents($argv[1]), true);
    echo (string) ($m["artifacts"]["bin-compile-aot"]["sha256"] ?? "");
  ' "${manifest}")"
  local got_bytes got_sha
  got_bytes="$(wc -c <"${driver}" | tr -d " ")"
  got_sha="$(sha256sum "${driver}" | awk "{print \$1}")"
  [[ "${got_bytes}" == "${expect_bytes}" && "${got_sha}" == "${expect_sha}" ]]
}

# Copy prelinked gen-0 driver into build/ when missing or stale.
bootstrap_gen0_seed_install() {
  local root="${ROOT:-${1:-}}"
  if [[ -z "${root}" ]]; then
    echo "bootstrap-gen0-seed: ROOT unset" >&2
    return 1
  fi
  if ! bootstrap_gen0_seed_verify "${root}"; then
    echo "bootstrap-gen0-seed: missing or corrupt prelinked/bootstrap-gen0 (manifest/driver/blob)" >&2
    return 1
  fi
  mkdir -p "${root}/build"
  local driver="${root}/prelinked/bootstrap-gen0/bin-compile-aot"
  local blob="${root}/prelinked/bootstrap-gen0/.m3_bin_compile_aot_blob"
  _bootstrap_gen0_seed_ensure_invoke_helpers
  if [[ -x "${root}/build/bin-compile-aot" ]] \
    && bootstrap_is_inventory_bin_compile_argv_driver "${root}/build/bin-compile-aot"; then
    return 0
  fi
  cp -f "${driver}" "${root}/build/bin-compile-aot"
  cp -f "${blob}" "${root}/build/.m3_bin_compile_aot_blob"
  chmod +x "${root}/build/bin-compile-aot" "${root}/build/.m3_bin_compile_aot_blob"
  echo "bootstrap-gen0-seed: installed gen-0 driver → build/bin-compile-aot (#3053)" >&2
}
