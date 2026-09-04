#!/usr/bin/env bash
# Slim + nyholm Composer project AOT build + phpc fcgi /hello smoke (#36382 Done-when).
#
# Usage (Docker / RunForge):
#   ./script/docker-exec.sh -- ./script/slim-hello-36382-smoke.sh
#   ./script/docker-exec.sh -- ./script/slim-hello-36382-smoke.sh --skip-setup
#
# Measured peak RSS during IncludeHelper for the 103-unit Slim graph is ~350 MiB;
# prior ~27 GiB climbs were NestedJIT wiping include-once dedupe (#36382). A later
# OOM under 8g was SprintfJitHelper::readPackedDoubleAtOffset calling unpack() while
# sprintf is force-NestedJIT'd into every user-script AOT — that pulled UnpackEngine
# into the user module. Fixed via Ieee754::decodeFloat64Le. Mid-graph first use of
# preg_replace_callback (Nyholm Uri::withUserInfo) NestedJITed PregJitHelperThinAot
# into the fat module for minutes — compile.php now eager-NestedJITs thin preg before
# IncludeHelper when unit count ≥ 32. Default Docker 8–10g is enough for full
# Slim+fcgi /hello after array-callable + charclass find (#36382).
# Keep LLVM memory floor at PHP_COMPILER_LLVM_MEMORY_LIMIT (default 8192M).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

# Slim-sized incremental graphs: use the configured LLVM budget (#36382).
export PHP_COMPILER_LLVM_MEMORY_LIMIT="${PHP_COMPILER_LLVM_MEMORY_LIMIT:-8192M}"

# shellcheck source=script/php-env.sh
source "$ROOT/script/php-env.sh"
# shellcheck source=script/ci-memory-env.sh
source "$ROOT/script/ci-memory-env.sh"
ci_apply_llvm_memory_env

DEST="${SLIM_HELLO_36382_DIR:-$ROOT/test/fixtures/aot/projects/slim_hello_36382}"
SKIP_SETUP=0
for arg in "$@"; do
  case "$arg" in
    --skip-setup) SKIP_SETUP=1 ;;
    -h|--help)
      sed -n '2,14p' "$0"
      exit 0
      ;;
  esac
done

if [[ "$SKIP_SETUP" -eq 0 ]] || [[ ! -f "$DEST/vendor/autoload.php" ]]; then
  echo "slim-hello-36382-smoke: ensuring fixture at $DEST"
  "$ROOT/script/setup-slim-hello-36382.sh" "$DEST"
fi

BIN="$DEST/.phpc/bin/slim-hello"
echo "slim-hello-36382-smoke: phpc build --project (LLVM mem=${PHP_COMPILER_MEMORY_LIMIT})"
php -d "memory_limit=${PHP_COMPILER_MEMORY_LIMIT}" bin/phpc.php build --project "$DEST"
test -x "$BIN"

LISTEN="${SLIM_HELLO_FCGI_LISTEN:-127.0.0.1:19082}"
HOST="${LISTEN%:*}"
PORT="${LISTEN##*:}"

echo "slim-hello-36382-smoke: phpc fcgi --listen $LISTEN"
php -d "memory_limit=${PHP_COMPILER_MEMORY_LIMIT}" bin/phpc.php fcgi \
  --listen "$LISTEN" \
  --project "$DEST" \
  --binary "$BIN" &
FCGI_PID=$!
cleanup() {
  kill "$FCGI_PID" 2>/dev/null || true
  wait "$FCGI_PID" 2>/dev/null || true
}
trap cleanup EXIT

# Wait for TCP accept
for _ in $(seq 1 50); do
  if (echo >/dev/tcp/"$HOST"/"$PORT") 2>/dev/null; then
    break
  fi
  sleep 0.1
done

# Minimal FastCGI GET /hello via php-cgi client when available; else CGI env on the binary.
BODY=""
if command -v cgi-fcgi >/dev/null 2>&1; then
  BODY="$(SCRIPT_NAME=/index.php REQUEST_URI=/hello REQUEST_METHOD=GET \
    cgi-fcgi -bind -connect "$LISTEN" 2>/dev/null | tr -d '\r' || true)"
fi

if [[ -z "$BODY" ]] || ! grep -q 'hello' <<<"$BODY"; then
  echo "slim-hello-36382-smoke: cgi-fcgi unavailable/empty — probing binary via CGI env"
  OUT="$(
    REQUEST_METHOD=GET \
    SCRIPT_NAME=/index.php \
    REQUEST_URI=/hello \
    PATH_INFO=/hello \
    QUERY_STRING= \
    SERVER_PROTOCOL=HTTP/1.1 \
    HTTP_HOST=127.0.0.1 \
    "$BIN" 2>&1 || true
  )"
  if ! grep -q 'hello' <<<"$OUT"; then
    echo "slim-hello-36382-smoke: FAIL — no 'hello' in response:" >&2
    printf '%s\n' "$OUT" >&2
    exit 1
  fi
  echo "slim-hello-36382-smoke: CGI hello OK"
else
  echo "slim-hello-36382-smoke: FastCGI hello OK"
fi

echo "slim-hello-36382-smoke: OK"
