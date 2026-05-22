#!/usr/bin/env bash
# CGI/1.1 wrapper for phpc-built AOT binaries (issue #665). No PHP required at runtime.
#
# Usage:
#   ./cgi-aot.sh /path/to/aot-binary
#   PHPC_DEPLOY_ROOT=/var/www/myapp ./cgi-aot.sh
#
# nginx example (dist layout from phpc deploy):
#   ScriptAlias /app /var/www/myapp/cgi-wrapper
set -euo pipefail

MAX_BODY=8388608
BODY_FILE=""
OUTFILE=""

usage() {
  echo "Usage: $(basename "$0") <aot-binary>" >&2
  echo "  or: PHPC_DEPLOY_ROOT=/path/to/dist $(basename "$0")" >&2
  exit 1
}

resolve_binary() {
  if [[ $# -ge 1 && -n "${1:-}" ]]; then
    if [[ ! -f "$1" ]]; then
      echo "cgi-aot: binary not found: $1" >&2
      exit 1
    fi
    printf '%s\n' "$(cd "$(dirname "$1")" && pwd)/$(basename "$1")"
    return 0
  fi

  local root="${PHPC_DEPLOY_ROOT:-}"
  if [[ -z "$root" ]]; then
    usage
  fi
  root="$(cd "$root" && pwd)"

  if [[ -f "${root}/bin/app" ]]; then
    printf '%s\n' "${root}/bin/app"
    return 0
  fi

  echo "cgi-aot: no bin/app under PHPC_DEPLOY_ROOT=${root}" >&2
  exit 1
}

ingest_stdin_body() {
  local len="${CONTENT_LENGTH:-}"
  if [[ -z "$len" || "$len" == "0" ]]; then
    return 0
  fi
  if ! [[ "$len" =~ ^[0-9]+$ ]]; then
    echo "cgi-aot: invalid CONTENT_LENGTH" >&2
    exit 1
  fi
  if [[ "$len" -gt "$MAX_BODY" ]]; then
    echo "cgi-aot: CONTENT_LENGTH exceeds limit" >&2
    exit 1
  fi

  BODY_FILE="$(mktemp)"
  export REQUEST_BODY_FILE="$BODY_FILE"
  dd if=/dev/stdin of="$BODY_FILE" bs=1 count="$len" status=none 2>/dev/null || {
    echo "cgi-aot: could not read request body" >&2
    exit 1
  }
  if [[ "${REQUEST_METHOD:-}" != "POST" ]]; then
    export REQUEST_METHOD=POST
  fi
}

BINARY="$(resolve_binary "${1:-}")"
if [[ ! -x "$BINARY" ]]; then
  chmod +x "$BINARY" 2>/dev/null || true
fi
if [[ ! -x "$BINARY" ]]; then
  echo "cgi-aot: binary is not executable: $BINARY" >&2
  exit 1
fi

if [[ -z "${PHPC_DEPLOY_ROOT:-}" ]]; then
  bin_dir="$(dirname "$BINARY")"
  if [[ "$(basename "$bin_dir")" == "bin" ]]; then
    export PHPC_DEPLOY_ROOT="$(cd "$bin_dir/.." && pwd)"
  fi
fi

ingest_stdin_body

OUTFILE="$(mktemp)"
cleanup() {
  if [[ -n "${OUTFILE}" && -f "${OUTFILE}" ]]; then
    rm -f "${OUTFILE}"
  fi
  if [[ -n "${BODY_FILE}" && -f "${BODY_FILE}" ]]; then
    rm -f "${BODY_FILE}"
  fi
}
trap cleanup EXIT

"$BINARY" >"$OUTFILE" 2>/dev/null || true
if [[ ! -s "$OUTFILE" ]]; then
  echo "cgi-aot: binary produced no output" >&2
  exit 1
fi

if grep -qi '^Status:' "$OUTFILE"; then
  cat "$OUTFILE"
  exit 0
fi

printf 'Status: 200 OK\r\n'
# AOT binaries emit a single CRLF between headers and body; CGI expects CRLF CRLF.
raw="$(<"$OUTFILE")"
printf '%s' "${raw/$'\r\n<'/$'\r\n\r\n<'}"
exit 0
