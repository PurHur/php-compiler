#!/usr/bin/env bash
#
# Cold-build check — how long does "clone the repo, compile hello world" actually take?
#
# This is the first thing a new user does, and until 2026-07-28 it cost ~9 minutes
# (#24302): warmForUserAotBuild() re-emitted the entire helper corpus on every clean
# checkout, because its only guard was a marker file under the gitignored
# build/helper-runtime-cache. #24351 skipped that when the committed per-arch cache
# matched core_fingerprint (~5s). #32122 also skips when units exist but the fingerprint
# drifted (patches/lock) — otherwise aot-smoke hits compile rc=124 inside 120s.
#
# Nothing was watching that number, so this exists to keep it from drifting back. It
# points PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR at a fresh temp directory, so it measures
# the cold path WITHOUT destroying the developer's real cache, and it bounds the run with
# a timeout — a regression shows up as a timeout rather than as a nine-minute wait.
#
# Usage:
#   script/cold-build-check.sh              # fail if slower than COLD_BUILD_MAX_SECONDS
#   COLD_BUILD_MAX_SECONDS=60 script/...    # override the budget
#   script/cold-build-check.sh --json       # machine-readable, for release-readiness
#   script/cold-build-check.sh --image      # Docker-only install path (#36390); budget 300s
#   script/cold-build-check.sh --image=phpc:local
#   script/cold-build-check.sh --sdk        # tarball SDK path (#36390); host PHP 8.2+ + bundled LLVM
#   PHPC_SDK_TARBALL=build/phpc-….tar.gz script/cold-build-check.sh --sdk
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT" || exit 1

JSON=0
IMAGE_MODE=0
SDK_MODE=0
IMAGE_TAG=""

for arg in "$@"; do
  case "$arg" in
    --json) JSON=1 ;;
    --image) IMAGE_MODE=1 ;;
    --image=*)
      IMAGE_MODE=1
      IMAGE_TAG="${arg#--image=}"
      ;;
    --sdk) SDK_MODE=1 ;;
    -h|--help)
      sed -n '2,25p' "$0" | tr -d '#'
      exit 0
      ;;
    *)
      echo "cold-build-check: unknown argument: ${arg}" >&2
      exit 2
      ;;
  esac
done

if [[ "$IMAGE_MODE" -eq 1 && "$SDK_MODE" -eq 1 ]]; then
  echo "cold-build-check: pass only one of --image / --sdk" >&2
  exit 2
fi

: "${PHP_BIN:=php}"
: "${PHP_COMPILER_LLVM_PATH:=/opt/llvm9}"
export PHP_COMPILER_LLVM_PATH
export LD_LIBRARY_PATH="${LD_LIBRARY_PATH:-}:${PHP_COMPILER_LLVM_PATH}"
: "${PHP_COMPILER_LLVM_MEMORY_LIMIT:=8192M}"
export PHP_COMPILER_LLVM_MEMORY_LIMIT

if [[ "$IMAGE_MODE" -eq 1 ]]; then
  # Fresh-machine path: only Docker required (#36390 Done-when ≤ 5 min).
  : "${COLD_BUILD_MAX_SECONDS:=300}"
  : "${PHPC_RELEASE_IMAGE:=${IMAGE_TAG:-phpc:local}}"

  if ! command -v docker >/dev/null 2>&1; then
    echo "cold-build-check: --image requires docker on the host" >&2
    exit 2
  fi
  if ! docker image inspect "$PHPC_RELEASE_IMAGE" >/dev/null 2>&1; then
    echo "cold-build-check: image ${PHPC_RELEASE_IMAGE} missing — run ./script/build-phpc-release-image.sh" >&2
    exit 2
  fi

  WORK="$(mktemp -d)"
  trap 'rm -rf "$WORK"' EXIT
  printf '%s\n' '<?php echo "hi\n";' > "$WORK/hello.php"

  # Prefer a bind-mount of WORK (real user path). On harnesses where the mount is
  # empty, fall back to docker create + cp (image correctness, no host mount).
  USE_MOUNT=1
  # shellcheck disable=SC2086
  if ! docker run --rm \
      ${HARNESS_DOCKER_RUN_OPTS:-} \
      ${PHP_COMPILER_DOCKER_RUN_OPTS:-} \
      -v "$WORK:/app" -w /app \
      --entrypoint test \
      "$PHPC_RELEASE_IMAGE" -f /app/hello.php 2>/dev/null
  then
    USE_MOUNT=0
  fi

  start=$(date +%s)
  if [[ "$USE_MOUNT" -eq 1 ]]; then
    # shellcheck disable=SC2086
    timeout "$COLD_BUILD_MAX_SECONDS" docker run --rm \
      ${HARNESS_DOCKER_RUN_OPTS:-} \
      ${PHP_COMPILER_DOCKER_RUN_OPTS:-} \
      -v "$WORK:/app" -w /app \
      "$PHPC_RELEASE_IMAGE" \
      build -o /app/hello.bin /app/hello.php > "$WORK/compile.log" 2>&1
    compile_rc=$?
  else
    # shellcheck disable=SC2086
    CID=$(docker create \
      ${HARNESS_DOCKER_RUN_OPTS:-} \
      ${PHP_COMPILER_DOCKER_RUN_OPTS:-} \
      -e PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR=/tmp/phpc-hr-cold-$$ \
      --entrypoint bash \
      "$PHPC_RELEASE_IMAGE" \
      -lc 'set -euo pipefail
mkdir -p "${PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR}"
printf "%s\n" "<?php echo \"hi\n\";" > /tmp/hello.php
/opt/phpc/phpc build -o /tmp/hello.bin /tmp/hello.php')
    timeout "$COLD_BUILD_MAX_SECONDS" docker start -a "$CID" > "$WORK/compile.log" 2>&1
    compile_rc=$?
    if [[ "$compile_rc" -eq 0 ]]; then
      docker cp "$CID:/tmp/hello.bin" "$WORK/hello.bin" >> "$WORK/compile.log" 2>&1 || compile_rc=$?
      chmod +x "$WORK/hello.bin" 2>/dev/null || true
    fi
    docker rm -f "$CID" >/dev/null 2>&1 || true
  fi
  end=$(date +%s)
  elapsed=$((end - start))

  status="ok"
  message=""

  if [ "$compile_rc" -eq 124 ]; then
    status="timeout"
    message="image compile exceeded ${COLD_BUILD_MAX_SECONDS}s (#36390 cold-install gate)"
  elif [ "$compile_rc" -ne 0 ]; then
    status="compile_failed"
    message="docker run ${PHPC_RELEASE_IMAGE} exited ${compile_rc}: $(tail -1 "$WORK/compile.log" 2>/dev/null)"
  elif [ ! -x "$WORK/hello.bin" ]; then
    status="no_binary"
    message="image compile reported success but emitted no binary"
  else
    actual="$("$WORK/hello.bin" 2>&1)"
    run_rc=$?
    if [ "$run_rc" -ne 0 ] || [ "$actual" != "hi" ]; then
      status="wrong_output"
      message="binary exited ${run_rc} with [${actual}], expected [hi]"
    fi
  fi

  if [ "$JSON" -eq 1 ]; then
    printf '{"status":"%s","mode":"image","image":"%s","seconds":%d,"budget_seconds":%d,"message":"%s"}\n' \
      "$status" "$PHPC_RELEASE_IMAGE" "$elapsed" "$COLD_BUILD_MAX_SECONDS" "${message//\"/\\\"}"
  else
    if [ "$status" = "ok" ]; then
      printf 'cold-build-check: ok — image %s hello world took %ds (budget %ds) (#36390)\n' \
        "$PHPC_RELEASE_IMAGE" "$elapsed" "$COLD_BUILD_MAX_SECONDS"
    else
      printf 'cold-build-check: FAIL (%s) after %ds — %s\n' "$status" "$elapsed" "$message" >&2
    fi
  fi

  [ "$status" = "ok" ] || exit 1
  exit 0
fi

if [[ "$SDK_MODE" -eq 1 ]]; then
  # Tarball SDK path: host PHP 8.2+ + bundled LLVM + committed helper cache (#36390 Done-when).
  # On harnesses without a usable host PHP, verify with the pinned dev image's php binary
  # against the extracted SDK tree (same relocatable layout as pack-phpc-sdk.sh).
  : "${COLD_BUILD_MAX_SECONDS:=300}"
  : "${PHPC_RELEASE_IMAGE:=${IMAGE_TAG:-phpc:local}}"
  : "${PHPC_RELEASE_BASE_IMAGE:=${PHP_COMPILER_DEV_IMAGE:-php-compiler:22.04-dev}}"

  if ! command -v docker >/dev/null 2>&1; then
    echo "cold-build-check: --sdk requires docker on the host (to stage/extract the SDK)" >&2
    exit 2
  fi

  WORK="$(mktemp -d)"
  SDK_ROOT=""
  CID=""
  cleanup_sdk() {
    [[ -n "$CID" ]] && docker rm -f "$CID" >/dev/null 2>&1 || true
    rm -rf "$WORK"
  }
  trap cleanup_sdk EXIT

  resolve_sdk_tree() {
    local tarball="${PHPC_SDK_TARBALL:-}"
    if [[ -z "$tarball" ]]; then
      # Prefer an already-packed SDK next to the repo (make pack-phpc-sdk).
      local cand
      shopt -s nullglob
      for cand in \
        "$REPO_ROOT"/build/phpc-*-x86_64-linux.tar.zst \
        "$REPO_ROOT"/build/phpc-*-x86_64-linux.tar.gz \
        "$REPO_ROOT"/build/phpc-*-aarch64-linux.tar.zst \
        "$REPO_ROOT"/build/phpc-*-aarch64-linux.tar.gz
      do
        if [[ -f "$cand" ]]; then
          tarball="$cand"
          break
        fi
      done
      shopt -u nullglob
    fi

    if [[ -n "$tarball" && -f "$tarball" ]]; then
      mkdir -p "$WORK/extract"
      case "$tarball" in
        *.tar.zst)
          if command -v zstd >/dev/null 2>&1; then
            zstd -dc "$tarball" | tar -C "$WORK/extract" -xf -
          else
            echo "cold-build-check: zstd required to unpack ${tarball}" >&2
            return 2
          fi
          ;;
        *.tar.gz) tar -C "$WORK/extract" -xzf "$tarball" ;;
        *)
          echo "cold-build-check: unsupported SDK archive: ${tarball}" >&2
          return 2
          ;;
      esac
      if [[ -d "$WORK/extract/phpc-sdk" ]]; then
        SDK_ROOT="$WORK/extract/phpc-sdk"
      else
        SDK_ROOT="$(find "$WORK/extract" -mindepth 1 -maxdepth 2 -type d -name 'phpc-sdk' | head -1)"
      fi
      if [[ -z "$SDK_ROOT" || ! -d "$SDK_ROOT" ]]; then
        echo "cold-build-check: tarball ${tarball} has no phpc-sdk/ root" >&2
        return 2
      fi
      echo "cold-build-check: using tarball ${tarball}" >&2
      return 0
    fi

    # No tarball — extract the same layout pack-phpc-sdk.sh writes from the release image.
    if ! docker image inspect "$PHPC_RELEASE_IMAGE" >/dev/null 2>&1; then
      echo "cold-build-check: image ${PHPC_RELEASE_IMAGE} missing and no PHPC_SDK_TARBALL — run make docker-build-phpc-release or make pack-phpc-sdk" >&2
      return 2
    fi
    CID="$(docker create "$PHPC_RELEASE_IMAGE")"
    mkdir -p "$WORK/phpc-sdk"
    docker cp "${CID}:/opt/phpc/." "$WORK/phpc-sdk/"
    docker cp "${CID}:/opt/llvm9/." "$WORK/phpc-sdk/llvm9/"
    docker rm -f "$CID" >/dev/null 2>&1 || true
    CID=""
    # Host wrapper (same as pack-phpc-sdk.sh) — used when host PHP is available.
    mkdir -p "$WORK/phpc-sdk/bin"
    cat > "$WORK/phpc-sdk/bin/phpc-host" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
export PHP_COMPILER_LLVM_PATH="${PHP_COMPILER_LLVM_PATH:-$ROOT/llvm9}"
export LD_LIBRARY_PATH="${LD_LIBRARY_PATH:-}:${PHP_COMPILER_LLVM_PATH}"
export PHPC_INVOKE_CWD="${PHPC_INVOKE_CWD:-${PWD}}"
PHP_BIN=""
for c in php8.2 php8.3 php8.4 php; do
  if command -v "$c" >/dev/null 2>&1; then PHP_BIN="$(command -v "$c")"; break; fi
done
if [[ -z "$PHP_BIN" ]]; then
  echo "phpc: need PHP 8.2+ on PATH (php8.2 or php)" >&2
  exit 127
fi
cd "$ROOT"
exec "$PHP_BIN" bin/phpc.php "$@"
EOF
    chmod +x "$WORK/phpc-sdk/bin/phpc-host"
    SDK_ROOT="$WORK/phpc-sdk"
    echo "cold-build-check: extracted SDK layout from ${PHPC_RELEASE_IMAGE}" >&2
    return 0
  }

  if ! resolve_sdk_tree; then
    exit 2
  fi

  # Helper cache must be present in the SDK — otherwise cold path re-emits the corpus (#24302).
  helper_units="$(find "$SDK_ROOT/prelinked/helper-runtime" -name 'unit.o' 2>/dev/null | wc -l | tr -d ' ')"
  if [[ "${helper_units:-0}" -lt 1 ]]; then
    echo "cold-build-check: SDK has zero helper-runtime unit.o — refusing (would re-emit corpus) (#36390)" >&2
    exit 1
  fi

  printf '%s\n' '<?php echo "hi\n";' > "$WORK/hello.php"
  mkdir -p "$WORK/out"

  # Prefer real host PHP (Done-when). Fall back to pinned image PHP + SDK tree/LLVM.
  USE_HOST_PHP=0
  for c in php8.2 php8.3 php8.4 php; do
    if command -v "$c" >/dev/null 2>&1; then
      # Host PHP on this harness is forbidden for unlimited memory — only use when
      # explicitly opted in (PHPC_SDK_USE_HOST_PHP=1) for maintainer laptops.
      if [[ "${PHPC_SDK_USE_HOST_PHP:-0}" = "1" ]]; then
        USE_HOST_PHP=1
        HOST_PHP_BIN="$(command -v "$c")"
      fi
      break
    fi
  done

  start=$(date +%s)
  if [[ "$USE_HOST_PHP" -eq 1 ]]; then
    export PHP_COMPILER_LLVM_PATH="${SDK_ROOT}/llvm9"
    export LD_LIBRARY_PATH="${LD_LIBRARY_PATH:-}:${PHP_COMPILER_LLVM_PATH}"
    export PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR="$WORK/cold-cache"
    mkdir -p "$PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR"
    timeout "$COLD_BUILD_MAX_SECONDS" \
      "$HOST_PHP_BIN" "${SDK_ROOT}/bin/phpc.php" \
      build -o "$WORK/out/hello.bin" "$WORK/hello.php" > "$WORK/compile.log" 2>&1
    compile_rc=$?
  else
    if ! docker image inspect "$PHPC_RELEASE_BASE_IMAGE" >/dev/null 2>&1; then
      echo "cold-build-check: base image ${PHPC_RELEASE_BASE_IMAGE} missing for SDK PHP fallback" >&2
      exit 2
    fi
    # Prefer bind-mount of SDK_ROOT. On harnesses where the mount is empty,
    # fall back to docker create + cp (same pattern as --image).
    USE_SDK_MOUNT=1
    # shellcheck disable=SC2086
    if ! docker run --rm \
        ${HARNESS_DOCKER_RUN_OPTS:-} \
        ${PHP_COMPILER_DOCKER_RUN_OPTS:-} \
        -v "$SDK_ROOT:/sdk" \
        --entrypoint test \
        "$PHPC_RELEASE_BASE_IMAGE" -f /sdk/bin/phpc.php 2>/dev/null
    then
      USE_SDK_MOUNT=0
    fi

    if [[ "$USE_SDK_MOUNT" -eq 1 ]]; then
      # shellcheck disable=SC2086
      timeout "$COLD_BUILD_MAX_SECONDS" docker run --rm \
        ${HARNESS_DOCKER_RUN_OPTS:-} \
        ${PHP_COMPILER_DOCKER_RUN_OPTS:-} \
        --memory=8g --memory-swap=8g \
        -v "$SDK_ROOT:/sdk" \
        -v "$WORK/hello.php:/tmp/hello.php:ro" \
        -v "$WORK/out:/out" \
        -e PHP_COMPILER_LLVM_PATH=/sdk/llvm9 \
        -e LD_LIBRARY_PATH=/sdk/llvm9 \
        -e PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR=/tmp/phpc-hr-sdk-cold-$$ \
        -e PHP_COMPILER_HELPER_RUNTIME_O=1 \
        --entrypoint bash \
        "$PHPC_RELEASE_BASE_IMAGE" \
        -lc 'set -euo pipefail
mkdir -p "$PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR"
php /sdk/bin/phpc.php build -o /out/hello.bin /tmp/hello.php' > "$WORK/compile.log" 2>&1
      compile_rc=$?
    else
      # shellcheck disable=SC2086
      CID=$(docker create \
        ${HARNESS_DOCKER_RUN_OPTS:-} \
        ${PHP_COMPILER_DOCKER_RUN_OPTS:-} \
        --memory=8g --memory-swap=8g \
        -e PHP_COMPILER_LLVM_PATH=/sdk/llvm9 \
        -e LD_LIBRARY_PATH=/sdk/llvm9 \
        -e PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR=/tmp/phpc-hr-sdk-cold-$$ \
        -e PHP_COMPILER_HELPER_RUNTIME_O=1 \
        --entrypoint bash \
        "$PHPC_RELEASE_BASE_IMAGE" \
        -lc 'set -euo pipefail
mkdir -p "$PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR" /out
printf "%s\n" "<?php echo \"hi\n\";" > /tmp/hello.php
php /sdk/bin/phpc.php build -o /out/hello.bin /tmp/hello.php')
      docker cp "$SDK_ROOT/." "$CID:/sdk/"
      timeout "$COLD_BUILD_MAX_SECONDS" docker start -a "$CID" > "$WORK/compile.log" 2>&1
      compile_rc=$?
      if [[ "$compile_rc" -eq 0 ]]; then
        docker cp "$CID:/out/hello.bin" "$WORK/out/hello.bin" >> "$WORK/compile.log" 2>&1 || compile_rc=$?
        chmod +x "$WORK/out/hello.bin" 2>/dev/null || true
      fi
      docker rm -f "$CID" >/dev/null 2>&1 || true
      CID=""
    fi
  fi
  end=$(date +%s)
  elapsed=$((end - start))

  status="ok"
  message=""

  if [ "$compile_rc" -eq 124 ]; then
    status="timeout"
    message="sdk compile exceeded ${COLD_BUILD_MAX_SECONDS}s (#36390 cold-install gate)"
  elif [ "$compile_rc" -ne 0 ]; then
    status="compile_failed"
    message="sdk phpc build exited ${compile_rc}: $(tail -1 "$WORK/compile.log" 2>/dev/null)"
  elif [ ! -x "$WORK/out/hello.bin" ]; then
    status="no_binary"
    message="sdk compile reported success but emitted no binary"
  else
    actual="$("$WORK/out/hello.bin" 2>&1)"
    run_rc=$?
    if [ "$run_rc" -ne 0 ] || [ "$actual" != "hi" ]; then
      status="wrong_output"
      message="binary exited ${run_rc} with [${actual}], expected [hi]"
    fi
  fi

  if [ "$JSON" -eq 1 ]; then
    printf '{"status":"%s","mode":"sdk","seconds":%d,"budget_seconds":%d,"helper_units":%d,"message":"%s"}\n' \
      "$status" "$elapsed" "$COLD_BUILD_MAX_SECONDS" "$helper_units" "${message//\"/\\\"}"
  else
    if [ "$status" = "ok" ]; then
      printf 'cold-build-check: ok — sdk hello world took %ds (budget %ds, %d helper unit.o) (#36390)\n' \
        "$elapsed" "$COLD_BUILD_MAX_SECONDS" "$helper_units"
    else
      printf 'cold-build-check: FAIL (%s) after %ds — %s\n' "$status" "$elapsed" "$message" >&2
      tail -20 "$WORK/compile.log" >&2 || true
    fi
  fi

  [ "$status" = "ok" ] || exit 1
  exit 0
fi

# Budget, not a benchmark. The measured good value is ~5s; 120s is far enough above it to
# absorb a slow CI runner while still catching a return to the ~500s behaviour.
: "${COLD_BUILD_MAX_SECONDS:=120}"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# A cache dir that has never been warmed — this is what a clean checkout has.
export PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR="$WORK/cold-cache"
mkdir -p "$PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR"

printf '%s\n' '<?php echo "hi\n";' > "$WORK/hello.php"

start=$(date +%s)
timeout "$COLD_BUILD_MAX_SECONDS" "$PHP_BIN" bin/compile.php \
    -o "$WORK/hello.bin" "$WORK/hello.php" > "$WORK/compile.log" 2>&1
compile_rc=$?
end=$(date +%s)
elapsed=$((end - start))

status="ok"
message=""

if [ "$compile_rc" -eq 124 ]; then
    status="timeout"
    message="compile exceeded ${COLD_BUILD_MAX_SECONDS}s on a cold cache — the corpus warmup is running again (#24302 / #24351)"
elif [ "$compile_rc" -ne 0 ]; then
    status="compile_failed"
    message="bin/compile.php exited ${compile_rc}: $(tail -1 "$WORK/compile.log" 2>/dev/null)"
elif [ ! -x "$WORK/hello.bin" ]; then
    status="no_binary"
    message="compile reported success but emitted no binary"
else
    actual="$("$WORK/hello.bin" 2>&1)"
    run_rc=$?
    if [ "$run_rc" -ne 0 ] || [ "$actual" != "hi" ]; then
        status="wrong_output"
        message="binary exited ${run_rc} with [${actual}], expected [hi]"
    fi
fi

if [ "$JSON" -eq 1 ]; then
    printf '{"status":"%s","mode":"checkout","seconds":%d,"budget_seconds":%d,"message":"%s"}\n' \
        "$status" "$elapsed" "$COLD_BUILD_MAX_SECONDS" "${message//\"/\\\"}"
else
    if [ "$status" = "ok" ]; then
        printf 'cold-build-check: ok — clean-checkout compile of hello world took %ds (budget %ds)\n' \
            "$elapsed" "$COLD_BUILD_MAX_SECONDS"
    else
        printf 'cold-build-check: FAIL (%s) after %ds — %s\n' "$status" "$elapsed" "$message" >&2
        echo "This is the first thing a new user does. See #24302 for why it was ~9 minutes and" >&2
        echo "#24351 for the fix (skip the corpus warmup when the committed prelink cache is current)." >&2
    fi
fi

[ "$status" = "ok" ] || exit 1
exit 0
