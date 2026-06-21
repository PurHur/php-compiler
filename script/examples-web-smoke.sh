#!/usr/bin/env bash
# HTTP curl harness for shipped web examples via phpc serve (issue #298).
#
# Starts phpc serve per docroot, curls representative URLs, exits non-zero on
# missing response needles or HTTP status other than 200.
#
# Local:
#   ./script/examples-web-smoke.sh
#   ./script/examples-web-smoke.sh --aot    # when .phpc/bin/app exists per example
#   ./script/examples-web-smoke.sh --jit    # phpc serve --jit (LLVM + MCJIT probe)
#
# Docker (harness-safe; same image as make test-harness):
#   ./script/docker-exec.sh -- ./script/examples-web-smoke.sh
#
# Skips with exit 0 when PHP_COMPILER_SKIP_SERVE_TESTS is set or loopback bind fails.
# 003-MiniWebApp --aot: home + hello PATH_INFO curls when .phpc/bin/app exists (#833, #676).
# See also: make web-smoke (lint + VM), examples/README.md (#262).
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
PHPC="${ROOT}/phpc"

usage() {
  cat <<'EOF'
Usage: script/examples-web-smoke.sh [--aot] [--jit] [--miniwebapp-only] [--sessions-only]

  VM mode (default): phpc serve per example docroot.
  --aot: use phpc serve --aot when <docroot>/.phpc/bin/app exists; skip otherwise.
         003-MiniWebApp: home + hello PATH_INFO + contact POST when stdout has MiniWebApp (#833, #676).
  --jit: use phpc serve --jit (007-ThrowsWeb: THROWSWEB_SERVE_JIT_SMOKE_GATE — #2408).
  --miniwebapp-only: curl only examples/003-MiniWebApp (MINIWEBAPP_WEB_SMOKE_GATE — #633).
  --sessions-only: curl only examples/005-SessionsWeb (SESSIONS_WEB_SMOKE_GATE — #1887).
  --fileupload-only: curl only examples/006-FileUploadWeb (FILE_UPLOAD_WEB_SMOKE_GATE — #1999).
  --throws-only: curl only examples/007-ThrowsWeb (THROWS_WEB_SMOKE_GATE — #2076).
  --fastcgi-only: curl only examples/009-FastCGIWeb (FASTCGI_WEB_SMOKE_GATE — #2351).

Environment:
  PHP_COMPILER_SKIP_SERVE_TESTS=1  exit 0 without running HTTP checks
  PHP_COMPILER_MAX_BODY            optional; 003 oversized POST check uses 1024 when unset (#705)
  MINIWEBAPP_AOT_EXECUTE_GATE=1    fail instead of skip when 003 AOT probe empty (#747, #676)
  MINIWEBAPP_WEB_SMOKE_AOT_GATE=1  require 003 --aot curls to pass (#833)
  SESSIONS_WEB_SMOKE_GATE=1        include 005 session flash curls in default run (#1887)
  SESSIONS_WEB_SERVE_AOT_SMOKE_GATE=1  require 005 phpc serve --aot session flash (#2333)
  FILE_UPLOAD_WEB_SMOKE_GATE=1     include 006 multipart upload curls (#1999)
  FILE_UPLOAD_WEB_SERVE_AOT_SMOKE_GATE=1  require 006 phpc serve --aot multipart POST (#2333)
  THROWS_WEB_SMOKE_GATE=1          include 007 throw/catch POST curls (#2076)
  THROWSWEB_SERVE_AOT_SMOKE_GATE=1 require 007 phpc serve --aot caught invalid POST (#2387)
  THROWSWEB_SERVE_JIT_SMOKE_GATE=1 require 007 phpc serve --jit caught invalid POST (#2408)
  THROWSWEB_UNCAUGHT_500_GATE=1    include 007 uncaught.php HTTP 500 curl (#2200)
  FASTCGI_WEB_SMOKE_GATE=1         include 009 health + PATH_INFO curls (default #2351, #2369)
EOF
}

AOT=0
JIT=0
MINIWEBAPP_ONLY=0
SESSIONS_ONLY=0
FILEUPLOAD_ONLY=0
THROWS_ONLY=0
FASTCGI_ONLY=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --aot) AOT=1; shift ;;
    --jit) JIT=1; shift ;;
    --miniwebapp-only) MINIWEBAPP_ONLY=1; shift ;;
    --sessions-only) SESSIONS_ONLY=1; shift ;;
    --fileupload-only) FILEUPLOAD_ONLY=1; shift ;;
    --throws-only) THROWS_ONLY=1; shift ;;
    --fastcgi-only) FASTCGI_ONLY=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "examples-web-smoke: unknown argument: $1" >&2; usage >&2; exit 1 ;;
  esac
done

if [[ -n "${PHP_COMPILER_SKIP_SERVE_TESTS:-}" ]]; then
  echo "examples-web-smoke: skipped (PHP_COMPILER_SKIP_SERVE_TESTS is set)"
  exit 0
fi

if ! "${ROOT}/script/php-local.sh" "${ROOT}/script/can-bind-loopback.php" >/dev/null 2>&1; then
  echo "examples-web-smoke: skipped (cannot bind loopback TCP)"
  exit 0
fi

if ! command -v curl >/dev/null 2>&1; then
  echo "examples-web-smoke: curl is required" >&2
  exit 1
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
  # phpc serve -> php-local.sh may run apply-patches on cold vendor trees (~17s); keep headroom (#298).
  local deadline=$((SECONDS + 25))
  while ((SECONDS < deadline)); do
    if (exec 3<>/dev/tcp/127.0.0.1/"${port}") 2>/dev/null; then
      exec 3<&-
      exec 3>&-
      return 0
    fi
    sleep 0.05
  done
  echo "examples-web-smoke: server on 127.0.0.1:${port} did not become ready" >&2
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
    echo "examples-web-smoke: ${label}: expected HTTP 200, got ${status}" >&2
    echo "  url: ${url}" >&2
    cat "$body" >&2 || true
    rm -f "$body"
    return 1
  fi
  for needle in "${needles[@]}"; do
    if ! grep -qF "$needle" "$body"; then
      echo "examples-web-smoke: ${label}: response missing needle: ${needle}" >&2
      echo "  url: ${url}" >&2
      cat "$body" >&2 || true
      rm -f "$body"
      return 1
    fi
  done
  rm -f "$body"
  echo "examples-web-smoke: ${label}: ok"
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
    echo "examples-web-smoke: ${label}: expected HTTP 200, got ${status}" >&2
    echo "  url: ${url}" >&2
    cat "$body" >&2 || true
    rm -f "$body"
    return 1
  fi
  for needle in "${needles[@]}"; do
    if ! grep -qF "$needle" "$body"; then
      echo "examples-web-smoke: ${label}: response missing needle: ${needle}" >&2
      echo "  url: ${url}" >&2
      cat "$body" >&2 || true
      rm -f "$body"
      return 1
    fi
  done
  rm -f "$body"
  echo "examples-web-smoke: ${label}: ok"
}

curl_expect_post_not_200() {
  local label="$1"
  local url="$2"
  local post_body="$3"
  local body status
  body="$(mktemp)"
  status="$(curl -sS -o "$body" -w '%{http_code}' --connect-timeout 5 --max-time 15 \
    -X POST -d "$post_body" "$url" || echo "000")"
  if [[ "$status" == "200" ]]; then
    echo "examples-web-smoke: ${label}: expected non-200 (413 or reset), got 200" >&2
    echo "  url: ${url}" >&2
    cat "$body" >&2 || true
    rm -f "$body"
    return 1
  fi
  rm -f "$body"
  echo "examples-web-smoke: ${label}: ok (HTTP ${status})"
}

curl_expect_500() {
  local label="$1"
  local url="$2"
  local body status
  body="$(mktemp)"
  status="$(curl -sS -o "$body" -w '%{http_code}' --connect-timeout 5 --max-time 15 "$url" || echo "000")"
  if [[ "$status" != "500" ]]; then
    echo "examples-web-smoke: ${label}: expected HTTP 500, got ${status}" >&2
    echo "  url: ${url}" >&2
    cat "$body" >&2 || true
    rm -f "$body"
    return 1
  fi
  rm -f "$body"
  echo "examples-web-smoke: ${label}: ok (HTTP 500)"
}

curl_expect_200_cookies() {
  local label="$1"
  local url="$2"
  local jar="$3"
  shift 3
  local needles=("$@")
  local body status
  body="$(mktemp)"
  status="$(curl -sS -o "$body" -w '%{http_code}' -b "$jar" -c "$jar" \
    --connect-timeout 5 --max-time 15 "$url" || echo "000")"
  if [[ "$status" != "200" ]]; then
    echo "examples-web-smoke: ${label}: expected HTTP 200, got ${status}" >&2
    echo "  url: ${url}" >&2
    cat "$body" >&2 || true
    rm -f "$body"
    return 1
  fi
  for needle in "${needles[@]}"; do
    if ! grep -qF "$needle" "$body"; then
      echo "examples-web-smoke: ${label}: response missing needle: ${needle}" >&2
      echo "  url: ${url}" >&2
      cat "$body" >&2 || true
      rm -f "$body"
      return 1
    fi
  done
  rm -f "$body"
  echo "examples-web-smoke: ${label}: ok"
}

curl_expect_200_multipart() {
  local label="$1"
  local url="$2"
  local field_name="$3"
  local file_path="$4"
  shift 4
  local needles=("$@")
  local body status
  body="$(mktemp)"
  status="$(curl -sS -o "$body" -w '%{http_code}' --connect-timeout 5 --max-time 15 \
    -F "${field_name}=@${file_path}" "$url" || echo "000")"
  if [[ "$status" != "200" ]]; then
    echo "examples-web-smoke: ${label}: expected HTTP 200, got ${status}" >&2
    echo "  url: ${url}" >&2
    cat "$body" >&2 || true
    rm -f "$body"
    return 1
  fi
  for needle in "${needles[@]}"; do
    if ! grep -qF "$needle" "$body"; then
      echo "examples-web-smoke: ${label}: response missing needle: ${needle}" >&2
      echo "  url: ${url}" >&2
      cat "$body" >&2 || true
      rm -f "$body"
      return 1
    fi
  done
  rm -f "$body"
  echo "examples-web-smoke: ${label}: ok"
}

curl_expect_303_post_cookies() {
  local label="$1"
  local url="$2"
  local jar="$3"
  local post_body="$4"
  local body status
  body="$(mktemp)"
  status="$(curl -sS -o "$body" -w '%{http_code}' -b "$jar" -c "$jar" \
    -X POST -d "$post_body" --connect-timeout 5 --max-time 15 "$url" || echo "000")"
  if [[ "$status" != "303" ]]; then
    echo "examples-web-smoke: ${label}: expected HTTP 303, got ${status}" >&2
    echo "  url: ${url}" >&2
    cat "$body" >&2 || true
    rm -f "$body"
    return 1
  fi
  rm -f "$body"
  echo "examples-web-smoke: ${label}: ok"
}

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

miniwebapp_aot_require_pass() {
  [[ "${MINIWEBAPP_AOT_EXECUTE_GATE:-0}" == "1" ]] \
    || [[ "${MINIWEBAPP_WEB_SMOKE_AOT_GATE:-1}" == "1" ]]
}

sessions_serve_aot_require_pass() {
  [[ "${SESSIONS_WEB_SERVE_AOT_SMOKE_GATE:-1}" == "1" ]]
}

file_upload_serve_aot_require_pass() {
  [[ "${FILE_UPLOAD_WEB_SERVE_AOT_SMOKE_GATE:-1}" == "1" ]]
}

throws_web_serve_aot_require_pass() {
  [[ "${THROWSWEB_SERVE_AOT_SMOKE_GATE:-1}" == "1" ]]
}

throws_web_serve_jit_require_pass() {
  [[ "${THROWSWEB_SERVE_JIT_SMOKE_GATE:-1}" == "1" ]]
}

ensure_jit_serve_ready() {
  local llvm_dir=""
  if ! llvm_dir="$(resolve_llvm_dir)"; then
    return 1
  fi
  export PHP_COMPILER_LLVM_PATH="$llvm_dir"
  unset PHP_COMPILER_SKIP_LLVM_PRELOAD
  if ! "${ROOT}/script/php-local.sh" "${ROOT}/script/jit-runtime-probe.php" >/dev/null 2>&1; then
    return 2
  fi
  return 0
}

ensure_project_aot_binary() {
  local project_dir="$1"
  local binary="${project_dir}/.phpc/bin/app"
  if [[ -x "$binary" ]]; then
    printf '%s' "$binary"
    return 0
  fi

  local llvm_dir=""
  if ! llvm_dir="$(resolve_llvm_dir)"; then
    return 1
  fi
  export PHP_COMPILER_LLVM_PATH="$llvm_dir"
  echo "examples-web-smoke: $(basename "$project_dir"): phpc build --project -> ${binary}" >&2
  if ! "$PHPC" build --project "$project_dir"; then
    return 1
  fi
  if [[ ! -x "$binary" ]]; then
    return 1
  fi
  printf '%s' "$binary"
}

# CLI byte probe aligned with MiniWebAppCgiEnv::shellQueryRouteHome (#773, #809).
miniwebapp_aot_stdout_ready() {
  local binary="$1"
  local out stderr_file run_code stderr
  stderr_file="$(mktemp)"
  set +e
  eval "$( "${ROOT}/script/miniwebapp-cgi-env.php" --export shellQueryRouteHome )"
  out="$("$binary" 2>"$stderr_file")"
  run_code=$?
  set -e
  stderr="$(cat "$stderr_file" 2>/dev/null || true)"
  rm -f "$stderr_file"

  if [[ "$run_code" -ne 0 ]]; then
    echo "examples-web-smoke: 003-MiniWebApp AOT probe: binary exited ${run_code}" >&2
    [[ -n "$stderr" ]] && echo "$stderr" >&2
    return 1
  fi
  if [[ -n "$stderr" ]]; then
    echo "examples-web-smoke: 003-MiniWebApp AOT probe: stderr: ${stderr}" >&2
    return 1
  fi
  if [[ -z "$out" ]] || [[ "$out" != *'MiniWebApp'* ]]; then
    return 1
  fi
  return 0
}

ensure_miniwebapp_aot_binary() {
  local project_dir="${ROOT}/${MINIWEBAPP}"
  local binary="${project_dir}/.phpc/bin/app"
  if [[ -x "$binary" ]]; then
    printf '%s' "$binary"
    return 0
  fi

  local llvm_dir=""
  if ! llvm_dir="$(resolve_llvm_dir)"; then
    return 1
  fi
  export PHP_COMPILER_LLVM_PATH="$llvm_dir"
  echo "examples-web-smoke: 003-MiniWebApp: phpc build --project -> ${binary}" >&2
  if ! "$PHPC" build --project "$project_dir"; then
    return 1
  fi
  if [[ ! -x "$binary" ]]; then
    return 1
  fi
  printf '%s' "$binary"
}

run_miniwebapp_aot_smoke() {
  local binary
  if ! binary="$(ensure_miniwebapp_aot_binary)"; then
    if miniwebapp_aot_require_pass; then
      echo "examples-web-smoke: 003-MiniWebApp: FAILED (no executable .phpc/bin/app)" >&2
      return 1
    fi
    echo "examples-web-smoke: 003-MiniWebApp: skip --aot (no executable .phpc/bin/app)"
    return 0
  fi

  if ! miniwebapp_aot_stdout_ready "$binary"; then
    if miniwebapp_aot_require_pass; then
      echo "examples-web-smoke: 003-MiniWebApp: FAILED (empty or wrong stdout; #676 execute parity)" >&2
      return 1
    fi
    echo "examples-web-smoke: 003-MiniWebApp: skip --aot (stdout probe not ready; #676)"
    return 0
  fi

  run_docroot_smoke "003-MiniWebApp" "${MINIWEBAPP}" \
    'GET|home PATH_INFO|/index.php|-|MiniWebApp;PATH_INFO' \
    'GET|hello PATH_INFO|/index.php/hello?name=Dev|-|Hello — MiniWebApp;Hello Dev' \
    'POST|contact PATH_INFO|/index.php/contact|name=PostDev|Thank you, PostDev'
}

run_miniwebapp_oversized_post_smoke() {
  local docroot="${ROOT}/${MINIWEBAPP}"
  if [[ ! -d "${docroot}/public" ]]; then
    return 0
  fi

  local max_body="${PHP_COMPILER_MAX_BODY:-1024}"
  local port pid
  port="$(find_free_port)"
  echo "examples-web-smoke: 003-MiniWebApp oversized POST (PHP_COMPILER_MAX_BODY=${max_body})"
  PHP_COMPILER_MAX_BODY="${max_body}" "${PHPC}" serve "127.0.0.1:${port}" "$docroot" >/dev/null 2>&1 &
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

  local limit=$((max_body + 1))
  local oversized_post
  oversized_post="$(printf "%${limit}s" '' | tr ' ' 'x')"
  curl_expect_post_not_200 \
    "003-MiniWebApp / oversized POST contact" \
    "http://127.0.0.1:${port}/index.php/contact" \
    "$oversized_post"

  stop_serve
  trap - RETURN
}

run_throws_web_smoke() {
  local docroot="${ROOT}/examples/007-ThrowsWeb"
  if [[ ! -d "$docroot" ]]; then
    echo "examples-web-smoke: 007-ThrowsWeb: skip (missing docroot)"
    return 0
  fi
  if [[ "$AOT" -eq 1 && "$JIT" -eq 1 ]]; then
    echo "examples-web-smoke: 007-ThrowsWeb: --aot and --jit are mutually exclusive" >&2
    return 1
  fi
  if [[ "$JIT" -eq 1 && "${THROWSWEB_SERVE_JIT_SMOKE_GATE:-1}" != "1" ]]; then
    echo "examples-web-smoke: 007-ThrowsWeb: skip --jit (THROWSWEB_SERVE_JIT_SMOKE_GATE=0; #2408, #2435)"
    return 0
  fi
  if [[ "$AOT" -eq 1 && "${THROWSWEB_SERVE_AOT_SMOKE_GATE:-1}" != "1" ]]; then
    echo "examples-web-smoke: 007-ThrowsWeb: skip --aot (THROWSWEB_SERVE_AOT_SMOKE_GATE=0; #2387, #2390)"
    return 0
  fi

  local port pid serve_cmd=("$PHPC" serve)
  local mode_label="VM throw/catch POST"
  if [[ "$JIT" -eq 1 ]]; then
    local jit_ready=0
    ensure_jit_serve_ready || jit_ready=$?
    if [[ "$jit_ready" -eq 1 ]]; then
      if throws_web_serve_jit_require_pass; then
        echo "examples-web-smoke: 007-ThrowsWeb: FAILED (LLVM 9 not available; #2408)" >&2
        return 1
      fi
      echo "examples-web-smoke: 007-ThrowsWeb: skip --jit (LLVM 9 not available)"
      return 0
    fi
    if [[ "$jit_ready" -eq 2 ]]; then
      if throws_web_serve_jit_require_pass; then
        echo "examples-web-smoke: 007-ThrowsWeb: FAILED (JIT MCJIT probe failed; #2408)" >&2
        return 1
      fi
      echo "examples-web-smoke: 007-ThrowsWeb: skip --jit (JIT MCJIT probe failed)"
      return 0
    fi
    serve_cmd=("$PHPC" serve --jit)
    mode_label="JIT throw/catch POST"
  elif [[ "$AOT" -eq 1 ]]; then
    local binary=""
    if ! binary="$(ensure_project_aot_binary "$docroot")"; then
      if throws_web_serve_aot_require_pass; then
        echo "examples-web-smoke: 007-ThrowsWeb: FAILED (no executable .phpc/bin/app; #2387)" >&2
        return 1
      fi
      echo "examples-web-smoke: 007-ThrowsWeb: skip --aot (no executable .phpc/bin/app)"
      return 0
    fi
    serve_cmd=("$PHPC" serve --aot --binary "$binary")
    mode_label="AOT throw/catch POST"
  fi

  port="$(find_free_port)"
  echo "examples-web-smoke: 007-ThrowsWeb on 127.0.0.1:${port} (${mode_label})"
  "${serve_cmd[@]}" "127.0.0.1:${port}" "$docroot" >/dev/null 2>&1 &
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

  if [[ "${THROWSWEB_UNCAUGHT_500_GATE:-0}" == "1" ]]; then
    if [[ ! -f "${docroot}/uncaught.php" ]]; then
      echo "examples-web-smoke: 007-ThrowsWeb: uncaught.php missing (THROWSWEB_UNCAUGHT_500_GATE=1)" >&2
      return 1
    fi
    curl_expect_500 "007-ThrowsWeb / uncaught.php" "${base}/uncaught.php"
  fi

  stop_serve
  trap - RETURN
}

run_file_upload_web_smoke() {
  local docroot="${ROOT}/examples/006-FileUploadWeb"
  if [[ ! -d "$docroot" ]]; then
    echo "examples-web-smoke: 006-FileUploadWeb: skip (missing docroot)"
    return 0
  fi
  if [[ "$AOT" -eq 1 && "${FILE_UPLOAD_WEB_SERVE_AOT_SMOKE_GATE:-1}" != "1" ]]; then
    echo "examples-web-smoke: 006-FileUploadWeb: skip --aot (FILE_UPLOAD_WEB_SERVE_AOT_SMOKE_GATE=0; #2333)"
    return 0
  fi

  local upload_file="${docroot}/README.md"
  if [[ ! -f "$upload_file" ]]; then
    echo "examples-web-smoke: 006-FileUploadWeb: skip (missing README.md for -F doc=@)" >&2
    return 1
  fi

  local port pid serve_cmd=("$PHPC" serve)
  local mode_label="VM multipart POST"
  if [[ "$AOT" -eq 1 ]]; then
    local binary=""
    if ! binary="$(ensure_project_aot_binary "$docroot")"; then
      if file_upload_serve_aot_require_pass; then
        echo "examples-web-smoke: 006-FileUploadWeb: FAILED (no executable .phpc/bin/app; #2333)" >&2
        return 1
      fi
      echo "examples-web-smoke: 006-FileUploadWeb: skip --aot (no executable .phpc/bin/app)"
      return 0
    fi
    serve_cmd=("$PHPC" serve --aot --binary "$binary")
    mode_label="AOT multipart POST"
  fi

  port="$(find_free_port)"
  echo "examples-web-smoke: 006-FileUploadWeb on 127.0.0.1:${port} (${mode_label})"
  "${serve_cmd[@]}" "127.0.0.1:${port}" "$docroot" >/dev/null 2>&1 &
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
  curl_expect_200 "006-FileUploadWeb / GET empty" "${base}/example.php" \
    "No upload yet"
  curl_expect_200_multipart "006-FileUploadWeb / POST multipart" \
    "${base}/example.php" "doc" "$upload_file" \
    "Uploaded: README.md"

  stop_serve
  trap - RETURN
}

run_sessions_web_smoke() {
  local docroot="${ROOT}/examples/005-SessionsWeb"
  if [[ ! -d "$docroot" ]]; then
    echo "examples-web-smoke: 005-SessionsWeb: skip (missing docroot)"
    return 0
  fi
  if [[ "$AOT" -eq 1 && "${SESSIONS_WEB_SERVE_AOT_SMOKE_GATE:-1}" != "1" ]]; then
    echo "examples-web-smoke: 005-SessionsWeb: skip --aot (SESSIONS_WEB_SERVE_AOT_SMOKE_GATE=0; #2333)"
    return 0
  fi

  local port pid jar serve_cmd=("$PHPC" serve)
  local mode_label="VM session flash"
  if [[ "$AOT" -eq 1 ]]; then
    local binary=""
    if ! binary="$(ensure_project_aot_binary "$docroot")"; then
      if sessions_serve_aot_require_pass; then
        echo "examples-web-smoke: 005-SessionsWeb: FAILED (no executable .phpc/bin/app; #2333)" >&2
        return 1
      fi
      echo "examples-web-smoke: 005-SessionsWeb: skip --aot (no executable .phpc/bin/app)"
      return 0
    fi
    serve_cmd=("$PHPC" serve --aot --binary "$binary")
    mode_label="AOT session flash"
  fi

  jar="$(mktemp)"
  port="$(find_free_port)"
  echo "examples-web-smoke: 005-SessionsWeb on 127.0.0.1:${port} (${mode_label})"
  "${serve_cmd[@]}" "127.0.0.1:${port}" "$docroot" >/dev/null 2>&1 &
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
  curl_expect_200_cookies "005-SessionsWeb / GET empty" "${base}/example.php" "$jar" \
    "No flash message yet"
  curl_expect_303_post_cookies "005-SessionsWeb / POST flash" "${base}/example.php" \
    "$jar" "message=Saved"
  curl_expect_200_cookies "005-SessionsWeb / GET flash" "${base}/example.php" "$jar" \
    "Flash: Saved"
  curl_expect_200_cookies "005-SessionsWeb / GET after flash" "${base}/example.php" "$jar" \
    "No flash message yet"

  rm -f "$jar"
  stop_serve
  trap - RETURN
}

run_fastcgi_web_smoke() {
  local docroot="${ROOT}/examples/009-FastCGIWeb"
  if [[ ! -d "$docroot" ]]; then
    echo "examples-web-smoke: 009-FastCGIWeb: skip (missing docroot)"
    return 0
  fi
  if [[ ! -f "${docroot}/example.php" ]]; then
    echo "examples-web-smoke: 009-FastCGIWeb: skip (example.php missing #2331)"
    return 0
  fi
  if [[ "$AOT" -eq 1 ]]; then
    echo "examples-web-smoke: 009-FastCGIWeb: skip --aot (VM serve smoke only; AOT via FASTCGI_WEB_AOT_SMOKE_GATE #2352)"
    return 0
  fi

  local port pid
  port="$(find_free_port)"
  echo "examples-web-smoke: 009-FastCGIWeb on 127.0.0.1:${port} (VM health + PATH_INFO)"
  "${PHPC}" serve "127.0.0.1:${port}" "$docroot" >/dev/null 2>&1 &
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
  curl_expect_200 "009-FastCGIWeb / GET health" "${base}/example.php" "ok"
  curl_expect_200 "009-FastCGIWeb / GET PATH_INFO ping" "${base}/example.php/ping" "PATH_INFO"

  stop_serve
  trap - RETURN
}

run_docroot_smoke() {
  local name="$1"
  local docroot="${ROOT}/${2}"
  shift 2
  # Remaining args: "METHOD|label|path|body|needle1;needle2;..."
  #   GET: body is "-" (no POST payload)
  #   POST: body is application/x-www-form-urlencoded (no "|" in body)
  if [[ ! -d "$docroot" ]]; then
    echo "examples-web-smoke: missing docroot ${docroot}" >&2
    return 1
  fi

  local port pid serve_cmd=("$PHPC" serve)
  if [[ "$AOT" -eq 1 ]]; then
    local binary="${docroot}/.phpc/bin/app"
    if [[ ! -x "$binary" ]]; then
      echo "examples-web-smoke: ${name}: skip --aot (no executable ${binary})"
      return 0
    fi
    serve_cmd=("$PHPC" serve --aot --binary "$binary")
  fi

  port="$(find_free_port)"
  echo "examples-web-smoke: ${name} on 127.0.0.1:${port} ($( [[ "$AOT" -eq 1 ]] && echo AOT || echo VM ))"
  # Detach from caller stdout/stderr (PHPUnit proc_open pipes can fill and deadlock).
  "${serve_cmd[@]}" "127.0.0.1:${port}" "$docroot" >/dev/null 2>&1 &
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
    IFS='|' read -r method label path post_body needles_str <<<"$1"
    shift
    local -a needles=()
    if [[ -n "$needles_str" ]]; then
      IFS=';' read -ra needles <<<"$needles_str"
    fi
    local url="${base}${path}"
    case "${method}" in
      GET)
        curl_expect_200 "$name / ${label}" "$url" "${needles[@]}"
        ;;
      POST)
        if [[ "$post_body" == "-" || -z "$post_body" ]]; then
          echo "examples-web-smoke: POST ${label} requires a form body" >&2
          return 1
        fi
        curl_expect_200_post "$name / ${label}" "$url" "$post_body" "${needles[@]}"
        ;;
      *)
        echo "examples-web-smoke: unknown method ${method} for ${label}" >&2
        return 1
        ;;
    esac
  done

  stop_serve
  trap - RETURN
}

mode_label="VM"
[[ "$AOT" -eq 1 ]] && mode_label="--aot"
[[ "$JIT" -eq 1 ]] && mode_label="--jit"
[[ "$MINIWEBAPP_ONLY" -eq 1 ]] && mode_label="${mode_label}; --miniwebapp-only"
[[ "$SESSIONS_ONLY" -eq 1 ]] && mode_label="${mode_label}; --sessions-only"
[[ "$FILEUPLOAD_ONLY" -eq 1 ]] && mode_label="${mode_label}; --fileupload-only"
[[ "$THROWS_ONLY" -eq 1 ]] && mode_label="${mode_label}; --throws-only"
[[ "$FASTCGI_ONLY" -eq 1 ]] && mode_label="${mode_label}; --fastcgi-only"
echo "examples-web-smoke: starting (${mode_label})"

if [[ "${FASTCGI_ONLY}" -eq 1 ]]; then
  run_fastcgi_web_smoke
  echo "examples-web-smoke: ok"
  exit 0
fi

if [[ "${THROWS_ONLY}" -eq 1 ]]; then
  run_throws_web_smoke
  echo "examples-web-smoke: ok"
  exit 0
fi

if [[ "${FILEUPLOAD_ONLY}" -eq 1 ]]; then
  run_file_upload_web_smoke
  echo "examples-web-smoke: ok"
  exit 0
fi

if [[ "${SESSIONS_ONLY}" -eq 1 ]]; then
  run_sessions_web_smoke
  echo "examples-web-smoke: ok"
  exit 0
fi

if [[ "${MINIWEBAPP_ONLY}" -eq 0 ]]; then
  run_docroot_smoke "001-SimpleWeb" "examples/001-SimpleWeb" \
    "GET|GET example.php?name=Smoke|/example.php?name=Smoke|-|Hello;Smoke" \
    "POST|POST example.php|/example.php|name=PostDev|Hello;PostDev" \
    "GET|static style.css|/style.css|-|body {"

  run_docroot_smoke "002-StaticWeb" "examples/002-StaticWeb" \
    "GET|GET example.php|/example.php|-|Hello;World" \
    "GET|GET / (example.php fallback)|/|-|Hello;World"

  run_docroot_smoke "004-ApiJson" "examples/004-ApiJson" \
    'GET|GET example.php|/example.php|-|"ok":true;php-compiler'

  if [[ "${SESSIONS_WEB_SMOKE_GATE:-1}" == "1" ]]; then
    run_sessions_web_smoke
  fi

  if [[ "${FILE_UPLOAD_WEB_SMOKE_GATE:-1}" == "1" ]]; then
    run_file_upload_web_smoke
  fi

  if [[ "${THROWS_WEB_SMOKE_GATE:-1}" == "1" ]]; then
    run_throws_web_smoke
  fi

  if [[ "${FASTCGI_WEB_SMOKE_GATE:-1}" == "1" ]]; then
    run_fastcgi_web_smoke
  fi
fi

MINIWEBAPP=examples/003-MiniWebApp
if [[ -d "${ROOT}/${MINIWEBAPP}/public" ]]; then
  miniwebapp_lint=0
  if ! "${PHPC}" lint --all "${MINIWEBAPP}" >/dev/null 2>&1; then
    miniwebapp_lint=$?
  fi
  if [[ "${miniwebapp_lint}" -ne 0 ]]; then
    echo "examples-web-smoke: 003-MiniWebApp: skip (lint exit ${miniwebapp_lint}; see ${MINIWEBAPP}/README.md)"
  elif [[ "$AOT" -eq 1 ]]; then
    run_miniwebapp_aot_smoke
  else
    run_docroot_smoke "003-MiniWebApp" "${MINIWEBAPP}" \
      'GET|home PATH_INFO|/index.php|-|MiniWebApp;PATH_INFO' \
      'GET|hello PATH_INFO|/index.php/hello?name=Dev|-|Hello — MiniWebApp;Hello Dev' \
      'POST|contact PATH_INFO|/index.php/contact|name=PostDev|Thank you, PostDev' \
      'GET|api/status PATH_INFO|/index.php/api/status|-|"ok":true;003-MiniWebApp' \
      'GET|home query fallback|/index.php?route=home|-|MiniWebApp' \
      'GET|assets style.css|/assets/style.css|-|body {'
    run_miniwebapp_oversized_post_smoke
  fi
fi

echo "examples-web-smoke: ok"
