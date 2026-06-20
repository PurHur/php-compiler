#!/usr/bin/env bash
# AOT build + CLI execute smoke for shipped examples (issue #667).
#
# Builds each example to .phpc/smoke/<name>/app, runs the native binary once,
# and checks stdout needles (no HTTP). Skips with exit 0 when LLVM 9 is missing.
#
# Usage:
#   ./script/examples-aot-smoke.sh
#   EXAMPLES_AOT_SMOKE_ONLY=003 ./script/examples-aot-smoke.sh   # 003 slice only (#683)
#   EXAMPLES_AOT_SMOKE_ONLY=005 ./script/examples-aot-smoke.sh   # 005 slice only (#1891)
#   EXAMPLES_AOT_SMOKE_ONLY=006 ./script/examples-aot-smoke.sh   # 006 slice only (#2013)
#   EXAMPLES_AOT_SMOKE_ONLY=007 ./script/examples-aot-smoke.sh   # 007 slice only (#2104)
#   THROWSWEB_AOT_SMOKE_GATE=1 EXAMPLES_AOT_SMOKE_ONLY=007 ./script/examples-aot-smoke.sh
#   EXAMPLES_AOT_SMOKE_ONLY=008 ./script/examples-aot-smoke.sh   # 008 slice only (#2407)
#   SELFHOSTPROBE_AOT_SMOKE_GATE=1 EXAMPLES_AOT_SMOKE_ONLY=008 ./script/examples-aot-smoke.sh
#   EXAMPLES_AOT_SMOKE_ONLY=009 ./script/examples-aot-smoke.sh   # 009 slice only (#2352)
#   FASTCGI_WEB_AOT_SMOKE_GATE=1 EXAMPLES_AOT_SMOKE_ONLY=009 ./script/examples-aot-smoke.sh
#
# Docker:
#   ./script/docker-exec.sh -- make examples-aot-smoke
#
# 003-MiniWebApp: link probe + execute bytes; fails when MINIWEBAPP_AOT_EXECUTE_GATE=1 and stdout empty (#747, #676).
set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$PWD"
PHPC="${ROOT}/phpc"
SMOKE_ROOT="${ROOT}/.phpc/smoke"
MINIWEBAPP="${ROOT}/examples/003-MiniWebApp"
SESSIONSWEB="${ROOT}/examples/005-SessionsWeb"
FILEUPLOADWEB="${ROOT}/examples/006-FileUploadWeb"
THROWSWEB="${ROOT}/examples/007-ThrowsWeb"
SELFHOSTPROBE="${ROOT}/examples/008-SelfHostProbe"
FASTCGIWEB="${ROOT}/examples/009-FastCGIWeb"
SMOKE_ONLY="${EXAMPLES_AOT_SMOKE_ONLY:-}"

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

LLVM_DIR=""
if ! LLVM_DIR="$(resolve_llvm_dir)"; then
  hint="${PHP_COMPILER_LLVM_PATH:-${ROOT}/.llvm}"
  echo "examples-aot-smoke: skipped (LLVM 9 not available at ${hint})"
  exit 0
fi
export PHP_COMPILER_LLVM_PATH="$LLVM_DIR"

# shellcheck source=php-env.sh
source "${ROOT}/script/php-env.sh"
export PHP_COMPILER_LLVM_PATH="$LLVM_DIR"

if [[ ! -x "$PHPC" ]]; then
  echo "examples-aot-smoke: phpc wrapper missing or not executable: ${PHPC}" >&2
  exit 1
fi

mkdir -p "$SMOKE_ROOT"

build_binary() {
  local label="$1"
  local source="$2"
  local outfile="$3"
  local bindir
  bindir="$(dirname "$outfile")"
  mkdir -p "$bindir"
  if [[ -x "$outfile" ]]; then
    rm -f "$outfile"
  fi
  echo "examples-aot-smoke: ${label}: phpc build -> ${outfile}"
  "$PHPC" build -o "$outfile" "$source"
  if [[ ! -x "$outfile" ]]; then
    echo "examples-aot-smoke: ${label}: expected executable ${outfile}" >&2
    exit 1
  fi
}

assert_needles() {
  local label="$1"
  local output="$2"
  shift 2
  local needle
  for needle in "$@"; do
    if [[ "$output" != *"$needle"* ]]; then
      echo "examples-aot-smoke: ${label}: stdout missing needle: ${needle}" >&2
      echo "--- stdout ---" >&2
      echo "$output" >&2
      echo "--- end ---" >&2
      exit 1
    fi
  done
}

run_binary() {
  local label="$1"
  local binary="$2"
  shift 2
  local stderr_file stdout stderr
  stderr_file="$(mktemp "${SMOKE_ROOT}/run.XXXXXX")"
  if (("$#" > 0)); then
    stdout="$(env "$@" "$binary" 2>"$stderr_file")"
  else
    stdout="$("$binary" 2>"$stderr_file")"
  fi
  local exit_code=$?
  stderr="$(cat "$stderr_file" 2>/dev/null || true)"
  rm -f "$stderr_file"
  if [[ "$exit_code" -ne 0 ]]; then
    echo "examples-aot-smoke: ${label}: binary exited ${exit_code}" >&2
    [[ -n "$stderr" ]] && echo "$stderr" >&2
    exit 1
  fi
  if [[ -n "$stderr" ]]; then
    echo "examples-aot-smoke: ${label}: stderr: ${stderr}" >&2
    exit 1
  fi
  printf '%s' "$stdout"
}

# 003-MiniWebApp project AOT + CLI execute (issue #485, #683, #809).
# Link + execute; empty stdout fails when MINIWEBAPP_AOT_EXECUTE_GATE=1 (default).
smoke_003_miniwebapp() {
  if [[ ! -d "${MINIWEBAPP}/public" ]]; then
    echo "examples-aot-smoke: 003-MiniWebApp: skip (tree missing #246)" >&2
    return 0
  fi

  local binary="${MINIWEBAPP}/.phpc/bin/app"
  echo "examples-aot-smoke: 003-MiniWebApp: phpc build --project -> ${binary}"
  if ! "$PHPC" build --project "${MINIWEBAPP}"; then
    echo "examples-aot-smoke: 003-MiniWebApp: link failed" >&2
    return 1
  fi
  if [[ ! -x "$binary" ]]; then
    echo "examples-aot-smoke: 003-MiniWebApp: expected executable ${binary}" >&2
    return 1
  fi

  local out stderr_file run_code
  stderr_file="$(mktemp "${SMOKE_ROOT}/003.XXXXXX")"
  set +e
  eval "$( "${ROOT}/script/miniwebapp-cgi-env.php" --export shellQueryRouteHome )"
  out="$("$binary" 2>"$stderr_file")"
  run_code=$?
  set -e
  local stderr
  stderr="$(cat "$stderr_file" 2>/dev/null || true)"
  rm -f "$stderr_file"

  if [[ "$run_code" -ne 0 ]]; then
    echo "examples-aot-smoke: 003-MiniWebApp: binary exited ${run_code}" >&2
    [[ -n "$stderr" ]] && echo "$stderr" >&2
    return 1
  fi
  if [[ -n "$stderr" ]]; then
    echo "examples-aot-smoke: 003-MiniWebApp: stderr: ${stderr}" >&2
    return 1
  fi

  if [[ -z "$out" ]] || [[ "$out" != *'MiniWebApp'* ]]; then
    if [[ "${MINIWEBAPP_AOT_EXECUTE_GATE:-1}" == "1" ]]; then
      echo "examples-aot-smoke: 003-MiniWebApp: FAILED (empty or wrong stdout)" >&2
      [[ -n "$out" ]] && echo "--- stdout ---" >&2 && echo "$out" >&2 && echo "--- end ---" >&2
      return 1
    fi
    echo "examples-aot-smoke: 003-MiniWebApp: skip (empty stdout; MINIWEBAPP_AOT_EXECUTE_GATE=0)" >&2
    return 0
  fi

  assert_needles '003-MiniWebApp' "$out" 'MiniWebApp'
  echo "examples-aot-smoke: 003-MiniWebApp: ok"
}

# 005-SessionsWeb project AOT + two-request session flash (#1891).
smoke_005_sessionsweb() {
  if [[ "${SESSIONS_WEB_AOT_SMOKE_GATE:-0}" != "1" ]]; then
    echo "examples-aot-smoke: 005-SessionsWeb: skip (SESSIONS_WEB_AOT_SMOKE_GATE=0)"
    return 0
  fi
  if [[ ! -f "${SESSIONSWEB}/example.php" ]]; then
    echo "examples-aot-smoke: 005-SessionsWeb: skip (tree missing #1881)" >&2
    return 0
  fi

  local binary="${SESSIONSWEB}/.phpc/bin/app"
  local session_dir
  session_dir="$(mktemp -d "${SMOKE_ROOT}/sess.XXXXXX")"
  echo "examples-aot-smoke: 005-SessionsWeb: phpc build --project -> ${binary}"
  if ! PHP_COMPILER_SESSION_DIR="$session_dir" "$PHPC" build --project "${SESSIONSWEB}"; then
    echo "examples-aot-smoke: 005-SessionsWeb: link failed" >&2
    rm -rf "$session_dir"
    return 1
  fi
  if [[ ! -x "$binary" ]]; then
    echo "examples-aot-smoke: 005-SessionsWeb: expected executable ${binary}" >&2
    rm -rf "$session_dir"
    return 1
  fi

  local cookie="" out stderr_file run_code stderr
  stderr_file="$(mktemp "${SMOKE_ROOT}/005.XXXXXX")"
  set +e
  out="$(
    PHP_COMPILER_SESSION_DIR="$session_dir" \
      REQUEST_METHOD='GET' SCRIPT_NAME='/example.php' REQUEST_URI='/example.php' \
      "$binary" 2>"$stderr_file"
  )"
  run_code=$?
  set -e
  stderr="$(cat "$stderr_file" 2>/dev/null || true)"
  rm -f "$stderr_file"
  if [[ "$run_code" -ne 0 ]]; then
    echo "examples-aot-smoke: 005-SessionsWeb: GET empty exited ${run_code}" >&2
    [[ -n "$stderr" ]] && echo "$stderr" >&2
    rm -rf "$session_dir"
    return 1
  fi
  if [[ "$out" != *'No flash message yet'* ]]; then
    echo "examples-aot-smoke: 005-SessionsWeb: GET empty missing needle" >&2
    rm -rf "$session_dir"
    return 1
  fi
  cookie="$(printf '%s' "$out" | awk '/^Set-Cookie: PHPSESSID=/{sub(/^Set-Cookie: /,""); sub(/;.*/,""); print; exit}')"
  if [[ -z "$cookie" ]]; then
    echo "examples-aot-smoke: 005-SessionsWeb: missing PHPSESSID Set-Cookie" >&2
    rm -rf "$session_dir"
    return 1
  fi

  stderr_file="$(mktemp "${SMOKE_ROOT}/005.XXXXXX")"
  set +e
  out="$(
    PHP_COMPILER_SESSION_DIR="$session_dir" HTTP_COOKIE="$cookie" \
      REQUEST_METHOD='POST' SCRIPT_NAME='/example.php' REQUEST_URI='/example.php' \
      REQUEST_BODY='message=Saved' CONTENT_LENGTH='13' \
      "$binary" 2>"$stderr_file"
  )"
  run_code=$?
  set -e
  stderr="$(cat "$stderr_file" 2>/dev/null || true)"
  rm -f "$stderr_file"
  if [[ "$run_code" -ne 0 ]]; then
    echo "examples-aot-smoke: 005-SessionsWeb: POST flash exited ${run_code}" >&2
    [[ -n "$stderr" ]] && echo "$stderr" >&2
    rm -rf "$session_dir"
    return 1
  fi
  stderr_file="$(mktemp "${SMOKE_ROOT}/005.XXXXXX")"
  set +e
  out="$(
    PHP_COMPILER_SESSION_DIR="$session_dir" HTTP_COOKIE="$cookie" \
      REQUEST_METHOD='GET' SCRIPT_NAME='/example.php' REQUEST_URI='/example.php' \
      "$binary" 2>"$stderr_file"
  )"
  run_code=$?
  set -e
  stderr="$(cat "$stderr_file" 2>/dev/null || true)"
  rm -f "$stderr_file"
  if [[ "$run_code" -ne 0 || "$out" != *'Flash: Saved'* ]]; then
    echo "examples-aot-smoke: 005-SessionsWeb: GET flash failed" >&2
    [[ -n "$stderr" ]] && echo "$stderr" >&2
    rm -rf "$session_dir"
    return 1
  fi

  rm -rf "$session_dir"
  echo "examples-aot-smoke: 005-SessionsWeb: ok"
}

# 006-FileUploadWeb project AOT + multipart CGI REQUEST_BODY (#1999, #2013).
smoke_006_fileuploadweb() {
  if [[ "${FILE_UPLOAD_WEB_AOT_SMOKE_GATE:-1}" != "1" ]]; then
    echo "examples-aot-smoke: 006-FileUploadWeb: skip (FILE_UPLOAD_WEB_AOT_SMOKE_GATE=0)"
    return 0
  fi
  if [[ ! -f "${FILEUPLOADWEB}/example.php" ]]; then
    echo "examples-aot-smoke: 006-FileUploadWeb: skip (tree missing #1999)" >&2
    return 0
  fi

  local binary="${FILEUPLOADWEB}/.phpc/bin/app"
  echo "examples-aot-smoke: 006-FileUploadWeb: phpc build --project -> ${binary}"
  if ! "$PHPC" build --project "${FILEUPLOADWEB}"; then
    echo "examples-aot-smoke: 006-FileUploadWeb: link failed" >&2
    return 1
  fi
  if [[ ! -x "$binary" ]]; then
    echo "examples-aot-smoke: 006-FileUploadWeb: expected executable ${binary}" >&2
    return 1
  fi

  local out stderr_file run_code stderr multipart_body
  multipart_body=$'--phpcFileB\r\nContent-Disposition: form-data; name="doc"; filename="README.md"\r\nContent-Type: text/plain\r\n\r\nbytes\r\n--phpcFileB--\r\n'
  stderr_file="$(mktemp "${SMOKE_ROOT}/006.XXXXXX")"
  set +e
  out="$(
    REQUEST_METHOD='POST' \
      REQUEST_BODY="$multipart_body" \
      CONTENT_TYPE='multipart/form-data; boundary=phpcFileB' \
      SCRIPT_NAME='/example.php' \
      REQUEST_URI='/example.php' \
      "$binary" 2>"$stderr_file"
  )"
  run_code=$?
  set -e
  stderr="$(cat "$stderr_file" 2>/dev/null || true)"
  rm -f "$stderr_file"
  if [[ "$run_code" -ne 0 ]]; then
    echo "examples-aot-smoke: 006-FileUploadWeb: multipart POST exited ${run_code}" >&2
    [[ -n "$stderr" ]] && echo "$stderr" >&2
    return 1
  fi
  if [[ "$out" != *'Uploaded: README.md'* ]]; then
    echo "examples-aot-smoke: 006-FileUploadWeb: missing upload needle" >&2
    [[ -n "$out" ]] && echo "--- stdout ---" >&2 && echo "$out" >&2 && echo "--- end ---" >&2
    return 1
  fi

  echo "examples-aot-smoke: 006-FileUploadWeb: ok"
}

# 007-ThrowsWeb project AOT + caught invalid POST CGI (#2101, #2104).
smoke_007_throwsweb() {
  if [[ "${THROWSWEB_AOT_SMOKE_GATE:-1}" != "1" ]]; then
    echo "examples-aot-smoke: 007-ThrowsWeb: skip (THROWSWEB_AOT_SMOKE_GATE=0)"
    return 0
  fi
  if [[ ! -f "${THROWSWEB}/example.php" ]]; then
    echo "examples-aot-smoke: 007-ThrowsWeb: skip (tree missing #2076)" >&2
    return 0
  fi

  local binary="${THROWSWEB}/.phpc/bin/app"
  echo "examples-aot-smoke: 007-ThrowsWeb: phpc build --project -> ${binary}"
  if ! "$PHPC" build --project "${THROWSWEB}"; then
    echo "examples-aot-smoke: 007-ThrowsWeb: link failed" >&2
    return 1
  fi
  if [[ ! -x "$binary" ]]; then
    echo "examples-aot-smoke: 007-ThrowsWeb: expected executable ${binary}" >&2
    return 1
  fi

  local out stderr_file run_code stderr
  stderr_file="$(mktemp "${SMOKE_ROOT}/007.XXXXXX")"
  set +e
  out="$(
    REQUEST_METHOD='POST' \
      REQUEST_BODY='email=bad' \
      CONTENT_TYPE='application/x-www-form-urlencoded' \
      SCRIPT_NAME='/example.php' \
      REQUEST_URI='/example.php' \
      "$binary" 2>"$stderr_file"
  )"
  run_code=$?
  set -e
  stderr="$(cat "$stderr_file" 2>/dev/null || true)"
  rm -f "$stderr_file"
  if [[ "$run_code" -ne 0 ]]; then
    echo "examples-aot-smoke: 007-ThrowsWeb: invalid POST exited ${run_code}" >&2
    [[ -n "$stderr" ]] && echo "$stderr" >&2
    return 1
  fi
  if [[ ! "$out" =~ [Ii]nvalid ]]; then
    echo "examples-aot-smoke: 007-ThrowsWeb: missing caught-error needle" >&2
    [[ -n "$out" ]] && echo "--- stdout ---" >&2 && echo "$out" >&2 && echo "--- end ---" >&2
    return 1
  fi

  echo "examples-aot-smoke: 007-ThrowsWeb: ok"
}

# 008-SelfHostProbe single-file AOT + CLI execute (#2407).
smoke_008_selfhostprobe() {
  if [[ "${SELFHOSTPROBE_AOT_SMOKE_GATE:-0}" != "1" ]]; then
    echo "examples-aot-smoke: 008-SelfHostProbe: skip (SELFHOSTPROBE_AOT_SMOKE_GATE=0)"
    return 0
  fi
  if [[ ! -f "${SELFHOSTPROBE}/example.php" ]]; then
    echo "examples-aot-smoke: 008-SelfHostProbe: skip (tree missing #2207)" >&2
    return 0
  fi

  local binary="${SMOKE_ROOT}/008-SelfHostProbe/app"
  echo "examples-aot-smoke: 008-SelfHostProbe: phpc build -> ${binary}"
  build_binary '008-SelfHostProbe' "${SELFHOSTPROBE}/example.php" "${binary}"
  local out
  out="$(run_binary '008-SelfHostProbe' "${binary}")"
  assert_needles '008-SelfHostProbe' "$out" 'SelfHostProbe'
  echo "examples-aot-smoke: 008-SelfHostProbe: ok"
}

# 009-FastCGIWeb project AOT + CGI health + PATH_INFO diagnostics (#2331, #2352).
smoke_009_fastcgiweb() {
  if [[ "${FASTCGI_WEB_AOT_SMOKE_GATE:-1}" != "1" ]]; then
    echo "examples-aot-smoke: 009-FastCGIWeb: skip (FASTCGI_WEB_AOT_SMOKE_GATE=0)"
    return 0
  fi
  if [[ ! -f "${FASTCGIWEB}/example.php" ]]; then
    echo "examples-aot-smoke: 009-FastCGIWeb: skip (tree missing #2331)" >&2
    return 0
  fi

  local binary="${FASTCGIWEB}/.phpc/bin/app"
  echo "examples-aot-smoke: 009-FastCGIWeb: phpc build --project -> ${binary}"
  if ! "$PHPC" build --project "${FASTCGIWEB}"; then
    echo "examples-aot-smoke: 009-FastCGIWeb: link failed" >&2
    return 1
  fi
  if [[ ! -x "$binary" ]]; then
    echo "examples-aot-smoke: 009-FastCGIWeb: expected executable ${binary}" >&2
    return 1
  fi

  local out stderr_file run_code stderr
  stderr_file="$(mktemp "${SMOKE_ROOT}/009.XXXXXX")"
  set +e
  out="$(
    QUERY_STRING='' \
      REQUEST_URI='/example.php' \
      SCRIPT_NAME='/example.php' \
      "$binary" 2>"$stderr_file"
  )"
  run_code=$?
  set -e
  stderr="$(cat "$stderr_file" 2>/dev/null || true)"
  rm -f "$stderr_file"
  if [[ "$run_code" -ne 0 ]]; then
    echo "examples-aot-smoke: 009-FastCGIWeb: health exited ${run_code}" >&2
    [[ -n "$stderr" ]] && echo "$stderr" >&2
    return 1
  fi
  if [[ "$out" != *'ok'* ]]; then
    echo "examples-aot-smoke: 009-FastCGIWeb: health missing ok needle" >&2
    [[ -n "$out" ]] && echo "--- stdout ---" >&2 && echo "$out" >&2 && echo "--- end ---" >&2
    return 1
  fi

  stderr_file="$(mktemp "${SMOKE_ROOT}/009.XXXXXX")"
  set +e
  out="$(
    PATH_INFO='/ping' \
      REQUEST_URI='/example.php/ping' \
      SCRIPT_NAME='/example.php' \
      "$binary" 2>"$stderr_file"
  )"
  run_code=$?
  set -e
  stderr="$(cat "$stderr_file" 2>/dev/null || true)"
  rm -f "$stderr_file"
  if [[ "$run_code" -ne 0 ]]; then
    echo "examples-aot-smoke: 009-FastCGIWeb: PATH_INFO exited ${run_code}" >&2
    [[ -n "$stderr" ]] && echo "$stderr" >&2
    return 1
  fi
  if [[ "$out" != *'PATH_INFO='* ]]; then
    echo "examples-aot-smoke: 009-FastCGIWeb: missing PATH_INFO diagnostic" >&2
    [[ -n "$out" ]] && echo "--- stdout ---" >&2 && echo "$out" >&2 && echo "--- end ---" >&2
    return 1
  fi

  echo "examples-aot-smoke: 009-FastCGIWeb: ok"
}

if [[ "${SMOKE_ONLY}" == "009" ]]; then
  echo "examples-aot-smoke: 009 slice (LLVM at ${LLVM_DIR})"
  smoke_009_fastcgiweb
  exit $?
fi

if [[ "${SMOKE_ONLY}" == "008" ]]; then
  echo "examples-aot-smoke: 008 slice (LLVM at ${LLVM_DIR})"
  smoke_008_selfhostprobe
  exit $?
fi

if [[ "${SMOKE_ONLY}" == "007" ]]; then
  echo "examples-aot-smoke: 007 slice (LLVM at ${LLVM_DIR})"
  smoke_007_throwsweb
  exit $?
fi

if [[ "${SMOKE_ONLY}" == "006" ]]; then
  echo "examples-aot-smoke: 006 slice (LLVM at ${LLVM_DIR})"
  smoke_006_fileuploadweb
  exit $?
fi

if [[ "${SMOKE_ONLY}" == "005" ]]; then
  echo "examples-aot-smoke: 005 slice (LLVM at ${LLVM_DIR})"
  smoke_005_sessionsweb
  exit $?
fi

if [[ "${SMOKE_ONLY}" == "003" ]]; then
  echo "examples-aot-smoke: 003 slice (LLVM at ${LLVM_DIR})"
  smoke_003_miniwebapp
  exit $?
fi

if [[ -n "${SMOKE_ONLY}" ]]; then
  echo "examples-aot-smoke: unknown EXAMPLES_AOT_SMOKE_ONLY=${SMOKE_ONLY}" >&2
  exit 1
fi

echo "examples-aot-smoke: starting (LLVM at ${LLVM_DIR})"

# 000-HelloWorld — single script, no phpc.json
build_binary '000-HelloWorld' \
  "${ROOT}/examples/000-HelloWorld/example.php" \
  "${SMOKE_ROOT}/000-HelloWorld/app"
out="$(run_binary '000-HelloWorld' "${SMOKE_ROOT}/000-HelloWorld/app")"
assert_needles '000-HelloWorld' "$out" 'Hello World'
echo "examples-aot-smoke: 000-HelloWorld: ok"

# 001-SimpleWeb — runtime QUERY_STRING refresh (issue #309)
build_binary '001-SimpleWeb' \
  "${ROOT}/examples/001-SimpleWeb/example.php" \
  "${SMOKE_ROOT}/001-SimpleWeb/app"
out="$(run_binary '001-SimpleWeb' "${SMOKE_ROOT}/001-SimpleWeb/app" \
  'QUERY_STRING=name=Smoke' \
  'SCRIPT_NAME=/example.php' \
  'REQUEST_URI=/example.php?name=Smoke')"
assert_needles '001-SimpleWeb' "$out" '<h1>Hello Smoke</h1>'
echo "examples-aot-smoke: 001-SimpleWeb: ok"

# 002-StaticWeb
build_binary '002-StaticWeb' \
  "${ROOT}/examples/002-StaticWeb/example.php" \
  "${SMOKE_ROOT}/002-StaticWeb/app"
out="$(run_binary '002-StaticWeb' "${SMOKE_ROOT}/002-StaticWeb/app")"
assert_needles '002-StaticWeb' "$out" 'Hello World'
echo "examples-aot-smoke: 002-StaticWeb: ok"

# 004-ApiJson
build_binary '004-ApiJson' \
  "${ROOT}/examples/004-ApiJson/example.php" \
  "${SMOKE_ROOT}/004-ApiJson/app"
out="$(run_binary '004-ApiJson' "${SMOKE_ROOT}/004-ApiJson/app")"
assert_needles '004-ApiJson' "$out" '"ok":true' 'php-compiler'
echo "examples-aot-smoke: 004-ApiJson: ok"

smoke_003_miniwebapp
smoke_005_sessionsweb
smoke_006_fileuploadweb
smoke_008_selfhostprobe
smoke_009_fastcgiweb

echo "examples-aot-smoke: ok"
