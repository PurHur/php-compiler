#!/usr/bin/env bash
# HTTP curl harness for shipped web examples via phpc serve (issue #298).
#
# Starts phpc serve per docroot, curls representative URLs, exits non-zero on
# missing response needles or HTTP status other than 200.
#
# Local:
#   ./script/examples-web-smoke.sh
#   ./script/examples-web-smoke.sh --aot    # when .phpc/bin/app exists per example
#
# Docker (same image as make test-docker):
#   docker run --rm -v "$(pwd):/compiler" -w /compiler php-compiler:22.04-dev ./script/examples-web-smoke.sh
#
# Skips with exit 0 when PHP_COMPILER_SKIP_SERVE_TESTS is set or loopback bind fails.
# See also: make web-smoke (lint + VM), examples/README.md (#262).
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
PHPC="${ROOT}/phpc"

usage() {
  cat <<'EOF'
Usage: script/examples-web-smoke.sh [--aot]

  VM mode (default): phpc serve per example docroot.
  --aot: use phpc serve --aot when <docroot>/.phpc/bin/app exists; skip otherwise.

Environment:
  PHP_COMPILER_SKIP_SERVE_TESTS=1  exit 0 without running HTTP checks
EOF
}

AOT=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --aot) AOT=1; shift ;;
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
  local deadline=$((SECONDS + 10))
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

echo "examples-web-smoke: starting ($( [[ "$AOT" -eq 1 ]] && echo --aot || echo VM ))"

run_docroot_smoke "001-SimpleWeb" "examples/001-SimpleWeb" \
  "GET|GET example.php?name=Smoke|/example.php?name=Smoke|-|Hello;Smoke" \
  "POST|POST example.php|/example.php|name=PostDev|Hello;PostDev" \
  "GET|static style.css|/style.css|-|body {"

run_docroot_smoke "002-StaticWeb" "examples/002-StaticWeb" \
  "GET|GET example.php|/example.php|-|Hello;World" \
  "GET|GET / (example.php fallback)|/|-|Hello;World"

run_docroot_smoke "004-ApiJson" "examples/004-ApiJson" \
  'GET|GET example.php|/example.php|-|"ok":true;php-compiler'

MINIWEBAPP=examples/003-MiniWebApp
if [[ -d "${ROOT}/${MINIWEBAPP}/public" ]]; then
  miniwebapp_lint=0
  if ! "${PHPC}" lint --all "${MINIWEBAPP}" >/dev/null 2>&1; then
    miniwebapp_lint=$?
  fi
  if [[ "${miniwebapp_lint}" -ne 0 ]]; then
    echo "examples-web-smoke: 003-MiniWebApp: skip (lint exit ${miniwebapp_lint}; see ${MINIWEBAPP}/README.md)"
  else
    run_docroot_smoke "003-MiniWebApp" "${MINIWEBAPP}" \
      'GET|home PATH_INFO|/index.php|-|MiniWebApp;PATH_INFO' \
      'GET|hello PATH_INFO|/index.php/hello?name=Dev|-|Hello — MiniWebApp;Hello Dev' \
      'POST|contact PATH_INFO|/index.php/contact|name=PostDev|Thank you, PostDev' \
      'GET|api/status PATH_INFO|/index.php/api/status|-|"ok":true;003-MiniWebApp' \
      'GET|home query fallback|/index.php?route=home|-|MiniWebApp'
  fi
fi

echo "examples-web-smoke: ok"
