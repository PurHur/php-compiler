#!/usr/bin/env bash
# phpc serve --jit e2e for 001-SimpleWeb (+ 003-MiniWebApp when project-ready, + 007-ThrowsWeb when lint green) (#2274, #2478).
#
# Usage:
#   SERVE_JIT_SMOKE_GATE=1 ./script/examples-serve-jit-smoke.sh
#   make examples-serve-jit-smoke
#
# Docker:
#   ./script/docker-exec.sh -- bash -lc 'source script/php-env.sh && SERVE_JIT_SMOKE_GATE=1 make examples-serve-jit-smoke'
set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$PWD"
PHPC="${ROOT}/phpc"

usage() {
  cat <<'EOF'
Usage: script/examples-serve-jit-smoke.sh

Environment:
  SERVE_JIT_SMOKE_GATE=1              required (script exits 0 when unset)
  THROWSWEB_SERVE_JIT_SMOKE_GATE=1    007 block when lint green (#2478, #2435); set 0 to skip
  PHP_COMPILER_SKIP_SERVE_TESTS=1     exit 0 without HTTP checks
EOF
}

if [[ "${SERVE_JIT_SMOKE_GATE:-0}" != "1" ]]; then
  echo "examples-serve-jit-smoke: skipped (SERVE_JIT_SMOKE_GATE=0; set 1 to run #2274)"
  exit 0
fi

if [[ -n "${PHP_COMPILER_SKIP_SERVE_TESTS:-}" ]]; then
  echo "examples-serve-jit-smoke: skipped (PHP_COMPILER_SKIP_SERVE_TESTS is set)"
  exit 0
fi

if ! "${ROOT}/script/php-local.sh" "${ROOT}/script/can-bind-loopback.php" >/dev/null 2>&1; then
  echo "examples-serve-jit-smoke: skipped (cannot bind loopback TCP)"
  exit 0
fi

if ! command -v curl >/dev/null 2>&1; then
  echo "examples-serve-jit-smoke: curl is required" >&2
  exit 1
fi

resolve_llvm_dir() {
  if [[ -n "${PHP_COMPILER_LLVM_PATH:-}" ]]; then
    if [[ -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
      echo "${PHP_COMPILER_LLVM_PATH}"
      return 0
    fi
    return 1
  fi
  if [[ -f "${ROOT}/.llvm/libLLVM-9.so.1" ]]; then
    echo "${ROOT}/.llvm"
    return 0
  fi
  if [[ -f /opt/llvm9/libLLVM-9.so.1 ]]; then
    echo /opt/llvm9
    return 0
  fi
  return 1
}

llvm_dir=""
if ! llvm_dir="$(resolve_llvm_dir)"; then
  echo "examples-serve-jit-smoke: skipped (LLVM 9 not available)"
  exit 0
fi
export PHP_COMPILER_LLVM_PATH="$llvm_dir"
unset PHP_COMPILER_SKIP_LLVM_PRELOAD

if ! "${ROOT}/script/php-local.sh" "${ROOT}/script/jit-runtime-probe.php" >/dev/null 2>&1; then
  echo "examples-serve-jit-smoke: skipped (JIT MCJIT probe failed; issue #98)"
  exit 0
fi

find_free_port() {
  "${ROOT}/script/php-local.sh" -r '
$s = @stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
if (false === $s) {
    fwrite(STDERR, "find port: {$errstr}\n");
    exit(1);
}
$name = stream_socket_get_name($s, false);
fclose($s);
if (!is_string($name) || !preg_match("#:(\d+)$#", $name, $m)) {
    fwrite(STDERR, "find port: invalid name\n");
    exit(1);
}
echo $m[1];
'
}

wait_for_serve() {
  local port="$1"
  local timeout="${PHP_COMPILER_SERVE_READY_TIMEOUT:-30}"
  local deadline=$((SECONDS + timeout))
  while ((SECONDS < deadline)); do
    if (exec 3<>/dev/tcp/127.0.0.1/"${port}") 2>/dev/null; then
      exec 3<&-
      exec 3>&-
      return 0
    fi
    sleep 0.05
  done
  echo "examples-serve-jit-smoke: server on 127.0.0.1:${port} did not become ready" >&2
  return 1
}

curl_expect_200() {
  local label="$1"
  local url="$2"
  shift 2
  local needles=("$@")
  local body status
  body="$(mktemp)"
  status="$(curl -sS -o "$body" -w '%{http_code}' --connect-timeout 5 --max-time 15 "$url" || echo "000")"
  if [[ "$status" != "200" ]]; then
    echo "examples-serve-jit-smoke: ${label}: expected HTTP 200, got ${status}" >&2
    echo "  url: ${url}" >&2
    cat "$body" >&2 || true
    rm -f "$body"
    return 1
  fi
  for needle in "${needles[@]}"; do
    if ! grep -qF "$needle" "$body"; then
      echo "examples-serve-jit-smoke: ${label}: response missing needle: ${needle}" >&2
      echo "  url: ${url}" >&2
      cat "$body" >&2 || true
      rm -f "$body"
      return 1
    fi
  done
  rm -f "$body"
  echo "examples-serve-jit-smoke: ${label}: ok"
}

curl_expect_200_post() {
  local label="$1"
  local url="$2"
  local post_body="$3"
  shift 3
  local needles=("$@")
  local body status
  body="$(mktemp)"
  status="$(curl -sS -o "$body" -w '%{http_code}' --connect-timeout 5 --max-time 15 \
    -X POST -d "$post_body" "$url" || echo "000")"
  if [[ "$status" != "200" ]]; then
    echo "examples-serve-jit-smoke: ${label}: expected HTTP 200, got ${status}" >&2
    echo "  url: ${url}" >&2
    cat "$body" >&2 || true
    rm -f "$body"
    return 1
  fi
  for needle in "${needles[@]}"; do
    if ! grep -qF "$needle" "$body"; then
      echo "examples-serve-jit-smoke: ${label}: response missing needle: ${needle}" >&2
      echo "  url: ${url}" >&2
      cat "$body" >&2 || true
      rm -f "$body"
      return 1
    fi
  done
  rm -f "$body"
  echo "examples-serve-jit-smoke: ${label}: ok"
}

run_serve_jit_smoke() {
  local name="$1"
  local docroot="$2"
  shift 2
  # Remaining: "label|path|needle1;needle2;..."
  if [[ ! -d "${docroot}" ]]; then
    echo "examples-serve-jit-smoke: ${name}: skip (missing docroot ${docroot})"
    return 0
  fi

  local port pid
  port="$(find_free_port)"
  echo "examples-serve-jit-smoke: ${name} on 127.0.0.1:${port} (phpc serve --jit)"
  "${PHPC}" serve --jit "127.0.0.1:${port}" "$docroot" >/dev/null 2>&1 &
  pid=$!

  stop_serve() {
    kill "$pid" 2>/dev/null || true
    local waited=0
    while kill -0 "$pid" 2>/dev/null && ((waited < 40)); do
      sleep 0.05
      waited=$((waited + 1))
    done
    if kill -0 "$pid" 2>/dev/null; then
      kill -9 "$pid" 2>/dev/null || true
    fi
    wait "$pid" 2>/dev/null || true
  }
  trap stop_serve RETURN

  wait_for_serve "$port"

  local base="http://127.0.0.1:${port}"
  while [[ $# -gt 0 ]]; do
    IFS='|' read -r label path needles_str <<<"$1"
    shift
    local -a needles=()
    if [[ -n "$needles_str" ]]; then
      IFS=';' read -ra needles <<<"$needles_str"
    fi
    curl_expect_200 "${name} / ${label}" "${base}${path}" "${needles[@]}"
  done

  stop_serve
  trap - RETURN
}

miniwebapp_jit_serve_ready() {
  local project="${ROOT}/examples/003-MiniWebApp"
  if [[ ! -d "${project}/public" ]]; then
    return 1
  fi
  if ! "${PHPC}" lint --all "${project}" >/dev/null 2>&1; then
    return 1
  fi
  return 0
}

throwsweb_jit_serve_ready() {
  local project="${ROOT}/examples/007-ThrowsWeb"
  if [[ ! -d "${project}" ]]; then
    return 1
  fi
  if ! "${PHPC}" lint "${project}/example.php" >/dev/null 2>&1; then
    return 1
  fi
  return 0
}

run_throwsweb_serve_jit_smoke() {
  local docroot="${ROOT}/examples/007-ThrowsWeb"
  if [[ ! -d "${docroot}" ]]; then
    echo "examples-serve-jit-smoke: 007-ThrowsWeb: skip (missing docroot)"
    return 0
  fi
  if [[ "${THROWSWEB_SERVE_JIT_SMOKE_GATE:-1}" != "1" ]]; then
    echo "examples-serve-jit-smoke: 007-ThrowsWeb: skip (THROWSWEB_SERVE_JIT_SMOKE_GATE=0; #2478, #2435)"
    return 0
  fi

  local port pid
  port="$(find_free_port)"
  echo "examples-serve-jit-smoke: 007-ThrowsWeb on 127.0.0.1:${port} (phpc serve --jit)"
  "${PHPC}" serve --jit "127.0.0.1:${port}" "$docroot" >/dev/null 2>&1 &
  pid=$!

  stop_serve() {
    kill "$pid" 2>/dev/null || true
    local waited=0
    while kill -0 "$pid" 2>/dev/null && ((waited < 40)); do
      sleep 0.05
      waited=$((waited + 1))
    done
    if kill -0 "$pid" 2>/dev/null; then
      kill -9 "$pid" 2>/dev/null || true
    fi
    wait "$pid" 2>/dev/null || true
  }
  trap stop_serve RETURN

  wait_for_serve "$port"

  local base="http://127.0.0.1:${port}"
  curl_expect_200 "007-ThrowsWeb / GET empty" "${base}/example.php" \
    "Submit an email"
  curl_expect_200_post "007-ThrowsWeb / POST invalid" "${base}/example.php" \
    "email=bad" \
    "Invalid email"

  stop_serve
  trap - RETURN
}

echo "examples-serve-jit-smoke: starting (SERVE_JIT_SMOKE_GATE=1, #2274)"

run_serve_jit_smoke "001-SimpleWeb" "${ROOT}/examples/001-SimpleWeb" \
  'GET Alice|/example.php?name=Alice|Hello;Alice' \
  'GET Bob|/example.php?name=Bob|Hello;Bob'

if miniwebapp_jit_serve_ready; then
  run_serve_jit_smoke "003-MiniWebApp" "${ROOT}/examples/003-MiniWebApp" \
    'GET home|/index.php|MiniWebApp;<title>Home' \
    'GET hello|/index.php/hello?name=Dev|Hello — MiniWebApp;Hello Dev'
else
  echo "examples-serve-jit-smoke: 003-MiniWebApp: skip (project includes #475 / #1770; lint --all not green)"
fi

if throwsweb_jit_serve_ready; then
  run_throwsweb_serve_jit_smoke
else
  echo "examples-serve-jit-smoke: 007-ThrowsWeb: skip (phpc lint example.php not green)"
fi

echo "examples-serve-jit-smoke: ok"
