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
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT" || exit 1

JSON=0
IMAGE_MODE=0
IMAGE_TAG=""

for arg in "$@"; do
  case "$arg" in
    --json) JSON=1 ;;
    --image) IMAGE_MODE=1 ;;
    --image=*)
      IMAGE_MODE=1
      IMAGE_TAG="${arg#--image=}"
      ;;
    -h|--help)
      sed -n '2,22p' "$0" | tr -d '#'
      exit 0
      ;;
    *)
      echo "cold-build-check: unknown argument: ${arg}" >&2
      exit 2
      ;;
  esac
done

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
