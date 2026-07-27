#!/usr/bin/env bash
# Exercise the committed gen-0 argv driver on a script it has never seen (#23468).
#
# Stamp/manifest sync alone stayed green while bin-compile-aot failed parseAndCompile
# on every input including hello-world. This probe asserts function:
#   committed driver -o OUT unique.php → runnable binary whose stdout matches Zend.
#
# Usage:
#   ./script/bootstrap-gen0-driver-functional-smoke.sh
#   make bootstrap-gen0-driver-functional-smoke
#   BOOTSTRAP_GEN0_DRIVER_FUNCTIONAL_GATE=1  # release-readiness / ci-fast opt-in→default
#
# Exit codes:
#   0  driver produced a matching runnable binary
#   1  driver missing, failed parse/compile, or output mismatch
#   2  LLVM/runtime prerequisites missing (skip-friendly for hosts without AOT)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"

DRIVER="${BOOTSTRAP_GEN0_DRIVER:-${ROOT}/prelinked/bootstrap-gen0/bin-compile-aot}"
WORKDIR="${BOOTSTRAP_GEN0_FUNCTIONAL_WORKDIR:-${ROOT}/build/gen0-driver-functional-smoke}"
TOKEN="gen0fw$(date -u +%Y%m%d%H%M%S)_${RANDOM}"
SRC="${WORKDIR}/never-seen-${TOKEN}.php"
OUT="${WORKDIR}/never-seen-${TOKEN}.bin"
ZEND_OUT="${WORKDIR}/never-seen-${TOKEN}.zend.out"
NATIVE_OUT="${WORKDIR}/never-seen-${TOKEN}.native.out"
EXPECT="GEN0_FUNCTIONAL_OK ${TOKEN}"

mkdir -p "${WORKDIR}"

if [[ ! -x "${DRIVER}" ]]; then
  echo "bootstrap-gen0-driver-functional-smoke: missing executable driver ${DRIVER}" >&2
  exit 1
fi

# Unique source the committed seed has never compiled (done-when for #23468).
cat >"${SRC}" <<EOF
<?php
echo "${EXPECT}\\n";
EOF

rm -f "${OUT}" "${ZEND_OUT}" "${NATIVE_OUT}"

echo "bootstrap-gen0-driver-functional-smoke: driver=${DRIVER}"
echo "bootstrap-gen0-driver-functional-smoke: source=${SRC}"

set +e
driver_log="$("${DRIVER}" -o "${OUT}" "${SRC}" 2>&1)"
driver_rc=$?
set -e

if [[ "${driver_rc}" -ne 0 ]]; then
  echo "bootstrap-gen0-driver-functional-smoke: FAILED — driver exit ${driver_rc}" >&2
  printf '%s\n' "${driver_log}" | tail -n 40 >&2
  if grep -qE 'parseAndCompile returned null|native emit failed at phase=parseAndCompile' <<<"${driver_log}"; then
    echo "bootstrap-gen0-driver-functional-smoke: committed seed cannot parse/compile (see #23468)" >&2
  fi
  exit 1
fi

if [[ ! -x "${OUT}" ]]; then
  echo "bootstrap-gen0-driver-functional-smoke: FAILED — no executable at ${OUT}" >&2
  printf '%s\n' "${driver_log}" | tail -n 20 >&2
  exit 1
fi

if ! grep -qE 'compile OK|helloworld_compile_smoke: compile OK|compile_smoke_m3_emit: compile OK' <<<"${driver_log}"; then
  # Some argv drivers are quiet on success; still require a runnable binary below.
  echo "bootstrap-gen0-driver-functional-smoke: note — no compile-OK banner (continuing with run check)"
fi

set +e
php -d display_errors=0 "${SRC}" >"${ZEND_OUT}" 2>"${WORKDIR}/zend.err"
zend_rc=$?
set -e
if [[ "${zend_rc}" -ne 0 ]]; then
  echo "bootstrap-gen0-driver-functional-smoke: FAILED — Zend reference run exit ${zend_rc}" >&2
  cat "${WORKDIR}/zend.err" >&2 || true
  exit 1
fi

set +e
"${OUT}" >"${NATIVE_OUT}" 2>"${WORKDIR}/native.err"
native_rc=$?
set -e
if [[ "${native_rc}" -ne 0 ]]; then
  echo "bootstrap-gen0-driver-functional-smoke: FAILED — native binary exit ${native_rc}" >&2
  cat "${WORKDIR}/native.err" >&2 || true
  exit 1
fi

if ! cmp -s "${ZEND_OUT}" "${NATIVE_OUT}"; then
  echo "bootstrap-gen0-driver-functional-smoke: FAILED — stdout mismatch vs Zend" >&2
  echo "  zend:   $(od -An -tx1 "${ZEND_OUT}" | head -c 120)" >&2
  echo "  native: $(od -An -tx1 "${NATIVE_OUT}" | head -c 120)" >&2
  exit 1
fi

if ! grep -qxF "${EXPECT}" "${NATIVE_OUT}"; then
  echo "bootstrap-gen0-driver-functional-smoke: FAILED — unexpected stdout (want token ${TOKEN})" >&2
  cat "${NATIVE_OUT}" >&2
  exit 1
fi

echo "bootstrap-gen0-driver-functional-smoke: OK — committed driver compiled never-seen script; stdout matches Zend"
echo "bootstrap-gen0-driver-functional-smoke: artifact=${OUT}"
exit 0
