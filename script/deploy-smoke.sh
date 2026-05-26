#!/usr/bin/env bash
# phpc deploy + PHPC_DEPLOY_ROOT CGI smoke (issue #718).
#
# Builds a shipped web example, runs phpc deploy, and executes bin/app under
# PHPC_DEPLOY_ROOT with CGI-style env (no HTTP server). Skips with exit 0 when
# LLVM 9 is missing. 003-MiniWebApp layout-only smoke: DEPLOY_SMOKE_003_LAYOUT=1 (#804).
# Full 003 execute: default on via DEPLOY_SMOKE_003_EXECUTE=1 (#1530); or MINIWEBAPP_AOT_EXECUTE_GATE=1 (#745).
#
# Usage:
#   ./script/deploy-smoke.sh
#   ./script/deploy-smoke.sh --example 001
#   ./script/deploy-smoke.sh --example 002
#   DEPLOY_SMOKE_003_LAYOUT=1 ./script/deploy-smoke.sh --example 003
#   ./script/deploy-smoke.sh --example 003
#   DEPLOY_SMOKE_003_EXECUTE=0 ./script/deploy-smoke.sh --example 003
#   DEPLOY_SMOKE_ONLY=003 make deploy-smoke
#   SESSIONS_WEB_DEPLOY_SMOKE_GATE=1 ./script/deploy-smoke.sh --example 005
#   FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE=1 ./script/deploy-smoke.sh --example 006
#   THROWSWEB_DEPLOY_SMOKE_GATE=1 ./script/deploy-smoke.sh --example 007
#   FASTCGI_WEB_DEPLOY_SMOKE_GATE=1 ./script/deploy-smoke.sh --example 009
#
# Docker (harness-safe):
#   ./script/docker-exec.sh -- make deploy-smoke
set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$PWD"
PHPC="${ROOT}/phpc"
SMOKE_ROOT="${ROOT}/.phpc/smoke/deploy"
MINIWEBAPP="${ROOT}/examples/003-MiniWebApp"
SESSIONS_WEB="${ROOT}/examples/005-SessionsWeb"
FILE_UPLOAD_WEB="${ROOT}/examples/006-FileUploadWeb"
THROWS_WEB="${ROOT}/examples/007-ThrowsWeb"
FASTCGI_WEB="${ROOT}/examples/009-FastCGIWeb"
EXAMPLE="002"
DEPLOY_SMOKE_ONLY="${DEPLOY_SMOKE_ONLY:-}"

usage() {
  cat <<'EOF' >&2
Usage: script/deploy-smoke.sh [--example 001|002|003|005|006|007|009]

  001  examples/001-SimpleWeb (QUERY_STRING=name=…)
  002  examples/002-StaticWeb (default; static HTML)
  003  examples/003-MiniWebApp (layout: DEPLOY_SMOKE_003_LAYOUT=1 #804;
                               execute: default on DEPLOY_SMOKE_003_EXECUTE=1 #1530;
                               or MINIWEBAPP_AOT_EXECUTE_GATE=1 #745)
  005  examples/005-SessionsWeb (SESSIONS_WEB_DEPLOY_SMOKE_GATE=1 #1893; cookie + POST redirect flash)
  006  examples/006-FileUploadWeb (FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE=1 #2028; multipart POST upload)
  007  examples/007-ThrowsWeb (THROWSWEB_DEPLOY_SMOKE_GATE=1 #2124; POST invalid email → caught HTML)
  009  examples/009-FastCGIWeb (FASTCGI_WEB_DEPLOY_SMOKE_GATE=1 #2359; health ok + PATH_INFO diagnostics)

003 execute smoke is default on (DEPLOY_SMOKE_003_EXECUTE=1); set DEPLOY_SMOKE_003_EXECUTE=0 to skip (#1530, #745).
005 requires SESSIONS_WEB_DEPLOY_SMOKE_GATE=1 (default 0 until stable — #1893).
006 requires FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE=1 (default 0 until stable — #2028).
007 requires THROWSWEB_DEPLOY_SMOKE_GATE=1 (default 0 until stable — #2124).
009 requires FASTCGI_WEB_DEPLOY_SMOKE_GATE=1 (default 0 until stable — #2359).
EOF
  exit 1
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --example)
      [[ $# -ge 2 ]] || usage
      EXAMPLE="$2"
      shift 2
      ;;
    -h|--help) usage ;;
    *) echo "deploy-smoke: unknown argument: $1" >&2; usage ;;
  esac
done

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
  echo "deploy-smoke: skipped (LLVM 9 not available at ${hint})"
  exit 0
fi
export PHP_COMPILER_LLVM_PATH="$LLVM_DIR"

# shellcheck source=php-env.sh
source "${ROOT}/script/php-env.sh"
export PHP_COMPILER_LLVM_PATH="$LLVM_DIR"

if [[ ! -x "$PHPC" ]]; then
  echo "deploy-smoke: phpc wrapper missing or not executable: ${PHPC}" >&2
  exit 1
fi

assert_needle() {
  local label="$1"
  local output="$2"
  shift 2
  local needle
  for needle in "$@"; do
    if [[ "$output" != *"$needle"* ]]; then
      echo "deploy-smoke: ${label}: output missing needle: ${needle}" >&2
      echo "--- output ---" >&2
      echo "$output" >&2
      echo "--- end ---" >&2
      exit 1
    fi
  done
}

run_deployed_app() {
  local label="$1"
  local dist="$2"
  shift 2
  local stderr_file stdout stderr
  stderr_file="$(mktemp "${SMOKE_ROOT}/run.XXXXXX")"
  if (("$#" > 0)); then
    stdout="$(env PHPC_DEPLOY_ROOT="$dist" "$@" "${dist}/bin/app" 2>"$stderr_file")"
  else
    stdout="$(env PHPC_DEPLOY_ROOT="$dist" "${dist}/bin/app" 2>"$stderr_file")"
  fi
  local exit_code=$?
  stderr="$(cat "$stderr_file" 2>/dev/null || true)"
  rm -f "$stderr_file"
  if [[ "$exit_code" -ne 0 ]]; then
    echo "deploy-smoke: ${label}: bin/app exited ${exit_code}" >&2
    [[ -n "$stderr" ]] && echo "$stderr" >&2
    exit 1
  fi
  if [[ -n "$stderr" ]]; then
    echo "deploy-smoke: ${label}: stderr: ${stderr}" >&2
    exit 1
  fi
  printf '%s' "$stdout"
}

deploy_smoke_005_enabled() {
  [[ "${SESSIONS_WEB_DEPLOY_SMOKE_GATE:-0}" == "1" ]]
}

deploy_smoke_006_enabled() {
  [[ "${FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE:-0}" == "1" ]]
}

deploy_smoke_007_enabled() {
  [[ "${THROWSWEB_DEPLOY_SMOKE_GATE:-0}" == "1" ]]
}

deploy_smoke_009_enabled() {
  [[ "${FASTCGI_WEB_DEPLOY_SMOKE_GATE:-0}" == "1" ]]
}

# Write multipart/form-data body for field doc=@file (issue #2028).
write_multipart_doc_body() {
  local out="$1"
  local upload_file="$2"
  local boundary="deploySmoke006B"
  local filename
  filename="$(basename "$upload_file")"
  {
    printf -- '--%s\r\n' "$boundary"
    printf 'Content-Disposition: form-data; name="doc"; filename="%s"\r\n' "$filename"
    printf 'Content-Type: application/octet-stream\r\n\r\n'
    cat "$upload_file"
    printf '\r\n--%s--\r\n' "$boundary"
  } >"$out"
}

run_deployed_cgi() {
  local label="$1"
  local dist="$2"
  local body_file="$3"
  shift 3
  local stderr_file stdout stderr exit_code content_length
  stderr_file="$(mktemp "${SMOKE_ROOT}/run.XXXXXX")"
  content_length="$(wc -c <"$body_file" | tr -d ' ')"
  local -a run_env=(PHPC_DEPLOY_ROOT="$dist" REQUEST_BODY_FILE="$body_file" "CONTENT_LENGTH=${content_length}")
  run_env+=("$@")
  stdout="$(env "${run_env[@]}" "${dist}/bin/app" 2>"$stderr_file")"
  exit_code=$?
  stderr="$(cat "$stderr_file" 2>/dev/null || true)"
  rm -f "$stderr_file"
  if [[ "$exit_code" -ne 0 ]]; then
    echo "deploy-smoke: ${label}: bin/app exited ${exit_code}" >&2
    [[ -n "$stderr" ]] && echo "$stderr" >&2
    exit 1
  fi
  if [[ -n "$stderr" ]]; then
    echo "deploy-smoke: ${label}: stderr: ${stderr}" >&2
    exit 1
  fi
  printf '%s' "$stdout"
}

extract_http_cookie_from_cgi() {
  local output="$1"
  local line sid
  line="$(printf '%s\n' "$output" | grep -i '^Set-Cookie: PHPSESSID=' | head -1 || true)"
  if [[ -z "$line" ]]; then
    return 0
  fi
  sid="$(printf '%s' "$line" | sed -n 's/^[Ss]et-[Cc]ookie: PHPSESSID=\([^;]*\).*/\1/p')"
  if [[ -n "$sid" ]]; then
    printf 'PHPSESSID=%s' "$sid"
  fi
}

run_deployed_sessions_cgi() {
  local label="$1"
  local dist="$2"
  local session_dir="$3"
  local http_cookie="$4"
  local stdin_body="$5"
  shift 5
  local stderr_file stdout stderr exit_code
  stderr_file="$(mktemp "${SMOKE_ROOT}/run.XXXXXX")"
  local -a run_env=(PHPC_DEPLOY_ROOT="$dist" PHP_COMPILER_SESSION_DIR="$session_dir")
  if [[ -n "$http_cookie" ]]; then
    run_env+=(HTTP_COOKIE="$http_cookie")
  fi
  run_env+=("$@")
  if [[ -n "$stdin_body" ]]; then
    stdout="$(printf '%s' "$stdin_body" | env "${run_env[@]}" "${dist}/bin/app" 2>"$stderr_file")"
  else
    stdout="$(env "${run_env[@]}" "${dist}/bin/app" 2>"$stderr_file")"
  fi
  exit_code=$?
  stderr="$(cat "$stderr_file" 2>/dev/null || true)"
  rm -f "$stderr_file"
  if [[ "$exit_code" -ne 0 ]]; then
    echo "deploy-smoke: ${label}: bin/app exited ${exit_code}" >&2
    [[ -n "$stderr" ]] && echo "$stderr" >&2
    exit 1
  fi
  if [[ -n "$stderr" ]]; then
    echo "deploy-smoke: ${label}: stderr: ${stderr}" >&2
    exit 1
  fi
  printf '%s' "$stdout"
}

smoke_deploy_example() {
  local id="$1"
  local project_rel="$2"
  local label="$3"
  local project="${ROOT}/${project_rel}"
  local dist="${SMOKE_ROOT}/${id}"
  local readme="${dist}/README.deploy"

  if [[ ! -d "$project" ]]; then
    echo "deploy-smoke: ${label}: skip (tree missing)" >&2
    return 0
  fi

  rm -rf "$dist"
  mkdir -p "$SMOKE_ROOT"

  echo "deploy-smoke: ${label}: phpc build --project"
  "$PHPC" build --project "$project"

  echo "deploy-smoke: ${label}: phpc deploy -> ${dist}"
  "$PHPC" deploy "$project" -o "$dist"

  if [[ ! -x "${dist}/bin/app" ]]; then
    echo "deploy-smoke: ${label}: expected executable ${dist}/bin/app" >&2
    exit 1
  fi
  if [[ ! -f "$readme" ]]; then
    echo "deploy-smoke: ${label}: expected ${readme}" >&2
    exit 1
  fi
  if ! grep -q 'PHPC_DEPLOY_ROOT' "$readme"; then
    echo "deploy-smoke: ${label}: README.deploy missing PHPC_DEPLOY_ROOT" >&2
    exit 1
  fi

  local out
  case "$id" in
    001-SimpleWeb)
      out="$(run_deployed_app "${label}" "$dist" \
        'QUERY_STRING=name=DeploySmoke' \
        'REQUEST_METHOD=GET' \
        'SCRIPT_NAME=/example.php' \
        'REQUEST_URI=/example.php?name=DeploySmoke')"
      assert_needle "${label}" "$out" '<h1>Hello DeploySmoke</h1>'
      ;;
    002-StaticWeb)
      out="$(run_deployed_app "${label}" "$dist")"
      assert_needle "${label}" "$out" 'Hello World'
      ;;
    *)
      echo "deploy-smoke: internal error: unknown example id ${id}" >&2
      exit 1
      ;;
  esac

  echo "deploy-smoke: ${label}: ok"
}

assert_layout_path() {
  local label="$1"
  local path="$2"
  local kind="$3"
  if [[ ! -e "$path" ]]; then
    echo "deploy-smoke: ${label}: expected ${kind} ${path}" >&2
    exit 1
  fi
}

smoke_003_layout_only() {
  local label="003-MiniWebApp"
  local dist="${SMOKE_ROOT}/003-MiniWebApp"
  local readme="${dist}/README.deploy"

  if [[ ! -d "${MINIWEBAPP}/public" ]]; then
    echo "deploy-smoke: ${label}: skip (tree missing #246)" >&2
    return 0
  fi

  rm -rf "$dist"
  mkdir -p "$SMOKE_ROOT"

  echo "deploy-smoke: ${label}: phpc build --project"
  "$PHPC" build --project "${MINIWEBAPP}"

  echo "deploy-smoke: ${label}: phpc deploy -> ${dist}"
  "$PHPC" deploy "${MINIWEBAPP}" -o "$dist"

  assert_layout_path "${label}" "${dist}/bin/app" "executable"
  if [[ ! -x "${dist}/bin/app" ]]; then
    echo "deploy-smoke: ${label}: expected executable ${dist}/bin/app" >&2
    exit 1
  fi
  assert_layout_path "${label}" "$readme" "file"
  if ! grep -q 'PHPC_DEPLOY_ROOT' "$readme"; then
    echo "deploy-smoke: ${label}: README.deploy missing PHPC_DEPLOY_ROOT" >&2
    exit 1
  fi
  assert_layout_path "${label}" "${dist}/templates" "directory"
  assert_layout_path "${label}" "${dist}/public/index.php" "file"
  assert_layout_path "${label}" "${dist}/cgi-wrapper" "file"

  local out stderr_file stderr exit_code
  stderr_file="$(mktemp "${SMOKE_ROOT}/run.XXXXXX")"
  out="$(env PHPC_DEPLOY_ROOT="$dist" \
    'QUERY_STRING=route=home' \
    'REQUEST_METHOD=GET' \
    "${dist}/bin/app" 2>"$stderr_file")" || true
  exit_code=$?
  stderr="$(cat "$stderr_file" 2>/dev/null || true)"
  rm -f "$stderr_file"
  if [[ "$exit_code" -ne 0 ]]; then
    echo "deploy-smoke: ${label}: layout ok; bin/app exited ${exit_code} (execute #764)" >&2
    [[ -n "$stderr" ]] && echo "$stderr" >&2
  elif [[ -z "$out" ]]; then
    echo "deploy-smoke: ${label}: layout ok; bin/app stdout empty (execute #764)" >&2
  else
    echo "deploy-smoke: ${label}: layout ok; bin/app stdout ${#out} bytes"
  fi

  echo "deploy-smoke: ${label}: layout ok"
}

deploy_smoke_003_execute_enabled() {
  [[ "${DEPLOY_SMOKE_003_EXECUTE:-1}" == "1" ]] \
    || [[ "${MINIWEBAPP_AOT_EXECUTE_GATE:-0}" == "1" ]]
}

run_deployed_miniwebapp() {
  local label="$1"
  local dist="$2"
  local scenario="$3"
  shift 3
  local stderr_file stdout stderr exit_code
  stderr_file="$(mktemp "${SMOKE_ROOT}/run.XXXXXX")"

  # shellcheck disable=SC1090
  eval "$( "${ROOT}/script/miniwebapp-cgi-env.php" --export "$scenario" )"
  export PHPC_DEPLOY_ROOT="$dist"
  export SCRIPT_FILENAME="${dist}/public/index.php"
  export SCRIPT_NAME='/index.php'
  export DOCUMENT_ROOT="${dist}/public"

  stdout="$("${dist}/bin/app" 2>"$stderr_file")"
  exit_code=$?
  stderr="$(cat "$stderr_file" 2>/dev/null || true)"
  rm -f "$stderr_file"
  if [[ "$exit_code" -ne 0 ]]; then
    echo "deploy-smoke: ${label}: bin/app exited ${exit_code} (scenario ${scenario})" >&2
    [[ -n "$stderr" ]] && echo "$stderr" >&2
    exit 1
  fi
  if [[ -n "$stderr" ]]; then
    echo "deploy-smoke: ${label}: stderr (scenario ${scenario}): ${stderr}" >&2
    exit 1
  fi
  assert_needle "${label} (${scenario})" "$stdout" "$@"
  printf '%s' "$stdout"
}

smoke_003_execute() {
  local label="003-MiniWebApp"
  local dist="${SMOKE_ROOT}/003-MiniWebApp"
  local readme="${dist}/README.deploy"

  rm -rf "$dist"
  mkdir -p "$SMOKE_ROOT"

  echo "deploy-smoke: ${label}: phpc build --project"
  "$PHPC" build --project "${MINIWEBAPP}"

  echo "deploy-smoke: ${label}: phpc deploy -> ${dist}"
  "$PHPC" deploy "${MINIWEBAPP}" -o "$dist"

  assert_layout_path "${label}" "${dist}/bin/app" "executable"
  if [[ ! -x "${dist}/bin/app" ]]; then
    echo "deploy-smoke: ${label}: expected executable ${dist}/bin/app" >&2
    exit 1
  fi
  assert_layout_path "${label}" "$readme" "file"
  if ! grep -q 'PHPC_DEPLOY_ROOT' "$readme"; then
    echo "deploy-smoke: ${label}: README.deploy missing PHPC_DEPLOY_ROOT" >&2
    exit 1
  fi
  assert_layout_path "${label}" "${dist}/templates" "directory"
  assert_layout_path "${label}" "${dist}/public/index.php" "file"
  assert_layout_path "${label}" "${dist}/cgi-wrapper" "file"

  local out
  out="$(run_deployed_miniwebapp "${label}" "$dist" shellQueryRouteHome 'MiniWebApp')"
  echo "deploy-smoke: ${label}: home route ok (${#out} bytes)"

  out="$(run_deployed_miniwebapp "${label}" "$dist" queryRouteHello 'Hello Dev')"
  echo "deploy-smoke: ${label}: hello route ok (${#out} bytes)"

  out="$(run_deployed_miniwebapp "${label}" "$dist" postQueryRouteContact 'Thank you, PostDev')"
  echo "deploy-smoke: ${label}: contact route ok (${#out} bytes)"

  echo "deploy-smoke: ${label}: ok"
}

smoke_003_miniwebapp() {
  if [[ ! -d "${MINIWEBAPP}/public" ]]; then
    echo "deploy-smoke: 003-MiniWebApp: skip (tree missing #246)" >&2
    return 0
  fi
  if [[ "${DEPLOY_SMOKE_003_LAYOUT:-0}" == "1" ]]; then
    smoke_003_layout_only
    return 0
  fi
  if deploy_smoke_003_execute_enabled; then
    smoke_003_execute
    return 0
  fi
  echo "deploy-smoke: 003-MiniWebApp: skip (DEPLOY_SMOKE_003_LAYOUT=1 layout #804; DEPLOY_SMOKE_003_EXECUTE=1 or MINIWEBAPP_AOT_EXECUTE_GATE=1 execute #745)" >&2
  return 0
}

smoke_005_sessions_web() {
  local label="005-SessionsWeb"
  local dist="${SMOKE_ROOT}/005-SessionsWeb"
  local readme="${dist}/README.deploy"
  local session_dir="${SMOKE_ROOT}/sessions-005"
  local out cookie post_body new_cookie

  if ! deploy_smoke_005_enabled; then
    echo "deploy-smoke: ${label}: skip (SESSIONS_WEB_DEPLOY_SMOKE_GATE=0 #1893)" >&2
    return 0
  fi

  if [[ ! -d "${SESSIONS_WEB}" ]]; then
    echo "deploy-smoke: ${label}: skip (tree missing #1881)" >&2
    return 0
  fi

  rm -rf "$dist" "$session_dir"
  mkdir -p "$SMOKE_ROOT" "$session_dir"

  echo "deploy-smoke: ${label}: phpc build --project"
  "$PHPC" build --project "${SESSIONS_WEB}"

  echo "deploy-smoke: ${label}: phpc deploy -> ${dist}"
  "$PHPC" deploy "${SESSIONS_WEB}" -o "$dist"

  if [[ ! -x "${dist}/bin/app" ]]; then
    echo "deploy-smoke: ${label}: expected executable ${dist}/bin/app" >&2
    exit 1
  fi
  if [[ ! -f "$readme" ]]; then
    echo "deploy-smoke: ${label}: expected ${readme}" >&2
    exit 1
  fi
  if ! grep -q 'PHPC_DEPLOY_ROOT' "$readme"; then
    echo "deploy-smoke: ${label}: README.deploy missing PHPC_DEPLOY_ROOT" >&2
    exit 1
  fi
  if ! grep -q 'PHP_COMPILER_SESSION_DIR' "$readme"; then
    echo "deploy-smoke: ${label}: README.deploy missing PHP_COMPILER_SESSION_DIR (#1893)" >&2
    exit 1
  fi

  post_body='message=Saved'

  out="$(run_deployed_sessions_cgi "${label} GET empty" "$dist" "$session_dir" "" "" \
    REQUEST_METHOD=GET SCRIPT_NAME=/example.php REQUEST_URI=/example.php QUERY_STRING=)"
  assert_needle "${label} GET empty" "$out" 'No flash message yet'
  cookie="$(extract_http_cookie_from_cgi "$out")"
  if [[ -z "$cookie" ]]; then
    echo "deploy-smoke: ${label}: login step: no PHPSESSID Set-Cookie after GET" >&2
    echo "--- output ---" >&2
    echo "$out" >&2
    echo "--- end ---" >&2
    exit 1
  fi
  echo "deploy-smoke: ${label}: session cookie ok"

  out="$(run_deployed_sessions_cgi "${label} POST flash" "$dist" "$session_dir" "$cookie" "$post_body" \
    REQUEST_METHOD=POST SCRIPT_NAME=/example.php REQUEST_URI=/example.php \
    CONTENT_TYPE=application/x-www-form-urlencoded "CONTENT_LENGTH=${#post_body}")"
  assert_needle "${label} POST flash" "$out" 'Status: 303'
  assert_needle "${label} POST flash" "$out" 'Location: /example.php'
  new_cookie="$(extract_http_cookie_from_cgi "$out")"
  if [[ -n "$new_cookie" ]]; then
    cookie="$new_cookie"
  fi
  echo "deploy-smoke: ${label}: POST redirect ok"

  out="$(run_deployed_sessions_cgi "${label} GET flash" "$dist" "$session_dir" "$cookie" "" \
    REQUEST_METHOD=GET SCRIPT_NAME=/example.php REQUEST_URI=/example.php QUERY_STRING=)"
  assert_needle "${label} GET flash" "$out" 'Flash: Saved'
  echo "deploy-smoke: ${label}: flash read ok"

  out="$(run_deployed_sessions_cgi "${label} GET after flash" "$dist" "$session_dir" "$cookie" "" \
    REQUEST_METHOD=GET SCRIPT_NAME=/example.php REQUEST_URI=/example.php QUERY_STRING=)"
  assert_needle "${label} GET after flash" "$out" 'No flash message yet'
  echo "deploy-smoke: ${label}: flash consumed ok"

  echo "deploy-smoke: ${label}: ok"
}

smoke_006_file_upload_web() {
  local label="006-FileUploadWeb"
  local dist="${SMOKE_ROOT}/006-FileUploadWeb"
  local readme="${dist}/README.deploy"
  local upload_file="${FILE_UPLOAD_WEB}/README.md"
  local body_file out

  if ! deploy_smoke_006_enabled; then
    echo "deploy-smoke: ${label}: skip (FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE=0 #2028)" >&2
    return 0
  fi

  if [[ ! -d "${FILE_UPLOAD_WEB}" ]]; then
    echo "deploy-smoke: ${label}: skip (tree missing #1999)" >&2
    return 0
  fi
  if [[ ! -f "$upload_file" ]]; then
    echo "deploy-smoke: ${label}: skip (missing README.md for multipart doc=@)" >&2
    return 0
  fi

  rm -rf "$dist"
  mkdir -p "$SMOKE_ROOT"

  echo "deploy-smoke: ${label}: phpc build --project"
  "$PHPC" build --project "${FILE_UPLOAD_WEB}"

  echo "deploy-smoke: ${label}: phpc deploy -> ${dist}"
  "$PHPC" deploy "${FILE_UPLOAD_WEB}" -o "$dist"

  if [[ ! -x "${dist}/bin/app" ]]; then
    echo "deploy-smoke: ${label}: expected executable ${dist}/bin/app" >&2
    exit 1
  fi
  if [[ ! -f "$readme" ]]; then
    echo "deploy-smoke: ${label}: expected ${readme}" >&2
    exit 1
  fi
  if ! grep -q 'PHPC_DEPLOY_ROOT' "$readme"; then
    echo "deploy-smoke: ${label}: README.deploy missing PHPC_DEPLOY_ROOT" >&2
    exit 1
  fi

  out="$(run_deployed_app "${label} GET empty" "$dist" \
    REQUEST_METHOD=GET SCRIPT_NAME=/example.php REQUEST_URI=/example.php QUERY_STRING=)"
  assert_needle "${label} GET empty" "$out" 'No upload yet'
  echo "deploy-smoke: ${label}: GET empty ok"

  body_file="$(mktemp "${SMOKE_ROOT}/multipart.XXXXXX")"
  write_multipart_doc_body "$body_file" "$upload_file"
  out="$(run_deployed_cgi "${label} POST multipart" "$dist" "$body_file" \
    REQUEST_METHOD=POST SCRIPT_NAME=/example.php REQUEST_URI=/example.php \
    'CONTENT_TYPE=multipart/form-data; boundary=deploySmoke006B')"
  rm -f "$body_file"
  assert_needle "${label} POST multipart" "$out" 'Uploaded: README.md'
  echo "deploy-smoke: ${label}: multipart upload ok"

  echo "deploy-smoke: ${label}: ok"
}

smoke_007_throws_web() {
  local label="007-ThrowsWeb"
  local dist="${SMOKE_ROOT}/007-ThrowsWeb"
  local readme="${dist}/README.deploy"
  local body_file out

  if ! deploy_smoke_007_enabled; then
    echo "deploy-smoke: ${label}: skip (THROWSWEB_DEPLOY_SMOKE_GATE=0 #2124)" >&2
    return 0
  fi

  if [[ ! -d "${THROWS_WEB}" ]]; then
    echo "deploy-smoke: ${label}: skip (tree missing #2076)" >&2
    return 0
  fi

  rm -rf "$dist"
  mkdir -p "$SMOKE_ROOT"

  echo "deploy-smoke: ${label}: phpc build --project"
  "$PHPC" build --project "${THROWS_WEB}"

  echo "deploy-smoke: ${label}: phpc deploy -> ${dist}"
  "$PHPC" deploy "${THROWS_WEB}" -o "$dist"

  if [[ ! -x "${dist}/bin/app" ]]; then
    echo "deploy-smoke: ${label}: expected executable ${dist}/bin/app" >&2
    exit 1
  fi
  if [[ ! -f "$readme" ]]; then
    echo "deploy-smoke: ${label}: expected ${readme}" >&2
    exit 1
  fi
  if ! grep -q 'PHPC_DEPLOY_ROOT' "$readme"; then
    echo "deploy-smoke: ${label}: README.deploy missing PHPC_DEPLOY_ROOT" >&2
    exit 1
  fi

  out="$(run_deployed_app "${label} GET empty" "$dist" \
    REQUEST_METHOD=GET SCRIPT_NAME=/example.php REQUEST_URI=/example.php QUERY_STRING=)"
  assert_needle "${label} GET empty" "$out" 'Submit an email'
  echo "deploy-smoke: ${label}: GET empty ok"

  body_file="$(mktemp "${SMOKE_ROOT}/post.XXXXXX")"
  printf 'email=bad' >"$body_file"
  out="$(run_deployed_cgi "${label} POST invalid" "$dist" "$body_file" \
    REQUEST_METHOD=POST SCRIPT_NAME=/example.php REQUEST_URI=/example.php \
    'CONTENT_TYPE=application/x-www-form-urlencoded')"
  rm -f "$body_file"
  assert_needle "${label} POST invalid" "$out" 'invalid'
  echo "deploy-smoke: ${label}: caught invalid POST ok"

  echo "deploy-smoke: ${label}: ok"
}

smoke_009_fastcgi_web() {
  local label="009-FastCGIWeb"
  local dist="${SMOKE_ROOT}/009-FastCGIWeb"
  local readme="${dist}/README.deploy"
  local out

  if ! deploy_smoke_009_enabled; then
    echo "deploy-smoke: ${label}: skip (FASTCGI_WEB_DEPLOY_SMOKE_GATE=0 #2359)" >&2
    return 0
  fi

  if [[ ! -f "${FASTCGI_WEB}/example.php" ]]; then
    echo "deploy-smoke: ${label}: skip (tree missing #2331)" >&2
    return 0
  fi

  rm -rf "$dist"
  mkdir -p "$SMOKE_ROOT"

  echo "deploy-smoke: ${label}: phpc build --project"
  "$PHPC" build --project "${FASTCGI_WEB}"

  echo "deploy-smoke: ${label}: phpc deploy -> ${dist}"
  "$PHPC" deploy "${FASTCGI_WEB}" -o "$dist"

  if [[ ! -x "${dist}/bin/app" ]]; then
    echo "deploy-smoke: ${label}: expected executable ${dist}/bin/app" >&2
    exit 1
  fi
  if [[ ! -f "$readme" ]]; then
    echo "deploy-smoke: ${label}: expected ${readme}" >&2
    exit 1
  fi
  if ! grep -q 'PHPC_DEPLOY_ROOT' "$readme"; then
    echo "deploy-smoke: ${label}: README.deploy missing PHPC_DEPLOY_ROOT" >&2
    exit 1
  fi

  out="$(run_deployed_app "${label} GET health" "$dist" \
    REQUEST_METHOD=GET SCRIPT_NAME=/example.php REQUEST_URI=/example.php QUERY_STRING=)"
  assert_needle "${label} GET health" "$out" 'ok'
  echo "deploy-smoke: ${label}: health ok"

  out="$(run_deployed_app "${label} GET PATH_INFO ping" "$dist" \
    REQUEST_METHOD=GET SCRIPT_NAME=/example.php REQUEST_URI=/example.php/ping PATH_INFO=/ping)"
  assert_needle "${label} GET PATH_INFO ping" "$out" 'PATH_INFO='
  echo "deploy-smoke: ${label}: PATH_INFO diagnostics ok"

  echo "deploy-smoke: ${label}: ok"
}

run_deploy_smoke_example() {
  case "$1" in
    001) smoke_deploy_example '001-SimpleWeb' 'examples/001-SimpleWeb' '001-SimpleWeb' ;;
    002) smoke_deploy_example '002-StaticWeb' 'examples/002-StaticWeb' '002-StaticWeb' ;;
    003) smoke_003_miniwebapp ;;
    005) smoke_005_sessions_web ;;
    006) smoke_006_file_upload_web ;;
    007) smoke_007_throws_web ;;
    009) smoke_009_fastcgi_web ;;
    *)
      echo "deploy-smoke: unknown example ${1} (use 001, 002, 003, 005, 006, 007, or 009)" >&2
      exit 1
      ;;
  esac
}

if [[ -n "${DEPLOY_SMOKE_ONLY}" ]]; then
  run_deploy_smoke_example "${DEPLOY_SMOKE_ONLY}"
else
  run_deploy_smoke_example "${EXAMPLE}"
fi

echo "deploy-smoke: ok"
