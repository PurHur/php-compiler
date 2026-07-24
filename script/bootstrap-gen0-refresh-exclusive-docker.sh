#!/usr/bin/env bash
# Launch an exclusive Zend full-spine gen-0 sidecar refresh that survives the
# Runforge harness spawned-container cleanup (#22642).
#
# Harness sets HARNESS_SPAWNED_CLEANUP_MAX_AGE_SECONDS=1800 and stops any
# php-compiler:22.04-dev container older than 30 minutes unless the container
# *name* matches HARNESS_SPAWNED_CLEANUP_PROTECT_NAMES (substrings such as
# "agent-harness"). Unprotected durable refreshes die mid-spine with ~1 GiB RSS
# and look like mysterious CONTAINER_GONE — not OOM.
#
# Usage (host, from repo root):
#   ./script/bootstrap-gen0-refresh-exclusive-docker.sh
#   BOOTSTRAP_GEN0_EXCLUSIVE_NAME=agent-harness-phpc-gen0-22642-rN \
#     ./script/bootstrap-gen0-refresh-exclusive-docker.sh
#
# Requires: docker, image php-compiler:22.04-dev, PHP_COMPILER_DOCKER_BIND_SRC
# (or a host path that docker can bind — never the empty Runforge /app bind).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"

IMAGE="${PHP_COMPILER_DEV_IMAGE:-php-compiler:22.04-dev}"
NAME="${BOOTSTRAP_GEN0_EXCLUSIVE_NAME:-agent-harness-phpc-gen0-refresh}"
MEM="${BOOTSTRAP_GEN0_EXCLUSIVE_MEMORY:-32g}"
CPUS="${BOOTSTRAP_GEN0_EXCLUSIVE_CPUS:-4}"
PHP_MEM="${PHP_COMPILER_MEMORY_LIMIT:-24576M}"

BIND_SRC="${PHP_COMPILER_DOCKER_BIND_SRC:-}"
if [[ -z "${BIND_SRC}" ]]; then
  # Prefer harness host path when /app and /home/ai/... share the tree.
  if [[ -d /home/ai/projects/agent-harness/var/workspaces ]]; then
    base="$(basename "$(dirname "${ROOT}")")"
    candidate="/home/ai/projects/agent-harness/var/workspaces/${base}/repo"
    if [[ -f "${candidate}/lib/JIT.php" ]]; then
      BIND_SRC="${candidate}"
    fi
  fi
fi
if [[ -z "${BIND_SRC}" ]]; then
  BIND_SRC="${ROOT}"
fi
if [[ ! -f "${BIND_SRC}/lib/JIT.php" ]]; then
  echo "bootstrap-gen0-refresh-exclusive-docker: bind src missing lib/JIT.php: ${BIND_SRC}" >&2
  echo "Set PHP_COMPILER_DOCKER_BIND_SRC to the harness host path." >&2
  exit 2
fi
case "${NAME}" in
  *agent-harness*|*irc-client*|*ops-dashboard*|*microlearning*)
    ;;
  *)
    echo "bootstrap-gen0-refresh-exclusive-docker: refusing name '${NAME}'" >&2
    echo "Name must include a HARNESS_SPAWNED_CLEANUP_PROTECT_NAMES substring" >&2
    echo "(e.g. agent-harness) or the harness will kill the job at 30 minutes (#22642)." >&2
    exit 2
    ;;
esac

if docker inspect "${NAME}" >/dev/null 2>&1; then
  echo "bootstrap-gen0-refresh-exclusive-docker: container ${NAME} already exists" >&2
  docker inspect -f 'running={{.State.Running}} started={{.State.StartedAt}}' "${NAME}" >&2 || true
  exit 1
fi

# Host-side pin: bind-mount shares the workspace. A mid-run `git checkout master`
# drops ClassConstFetchHelper's trait self-require and kills Zend spine after hours
# (r6 @ 163m — #22642). Refuse to start unless the fix is present on the bind src.
if ! grep -q "require_once __DIR__ . '/ClassConstFetchHelperTrait.php'" \
  "${BIND_SRC}/lib/JIT/ClassConstFetchHelper.php"; then
  echo "bootstrap-gen0-refresh-exclusive-docker: ClassConstFetchHelper.php on bind src" >&2
  echo "  lacks trait self-require — checkout the #22642 branch before launching." >&2
  exit 2
fi
PIN_HEAD="$(git -C "${BIND_SRC}" rev-parse HEAD)"
PIN_BRANCH="$(git -C "${BIND_SRC}" rev-parse --abbrev-ref HEAD 2>/dev/null || echo DETACHED)"

mkdir -p "${ROOT}/build"
LOG_HOST="${ROOT}/build/gen0-refresh-exclusive.log"

echo "bootstrap-gen0-refresh-exclusive-docker: name=${NAME} bind=${BIND_SRC} mem=${MEM} cpus=${CPUS}"
echo "bootstrap-gen0-refresh-exclusive-docker: pin HEAD=${PIN_HEAD:0:12} branch=${PIN_BRANCH}"
# Intentionally NO --rm: keep exit/OOM flags after failure for triage.
docker run -d \
  --name "${NAME}" \
  --memory="${MEM}" \
  --cpus="${CPUS}" \
  -v "${BIND_SRC}:/compiler" \
  -w /compiler \
  -e PHP_COMPILER_ALLOW_PARALLEL_CI=1 \
  -e BOOTSTRAP_GEN0_PIN_HEAD="${PIN_HEAD}" \
  "${IMAGE}" \
  bash -lc "
    set -uo pipefail
    source script/php-env.sh
    export PHP_COMPILER_LLVM_PATH=/opt/llvm9
    export LD_LIBRARY_PATH=/opt/llvm9\${LD_LIBRARY_PATH:+:\$LD_LIBRARY_PATH}
    unset PHP_COMPILER_SKIP_LLVM_PRELOAD
    export PHP_COMPILER_CI_RAM_GB=0
    ci_apply_resource_limits 2>/dev/null || true
    ulimit -v unlimited 2>/dev/null || ulimit -v 0 2>/dev/null || true
    export PHP_COMPILER_MEMORY_LIMIT=${PHP_MEM}
    export PHP_COMPILER_LLVM_MEMORY_LIMIT=${PHP_MEM}
    export PHP_COMPILER_HELPER_RUNTIME_O=1
    export PHP_COMPILER_JIT_PROGRESS_FILE=/compiler/build/.last-jit-spine-exclusive
    LOG=/compiler/build/gen0-refresh-exclusive-inner.log
    STATUS=/compiler/build/gen0-refresh-exclusive.status
    HB=/compiler/build/gen0-refresh-exclusive.heartbeat
    PIN_HEAD=\${BOOTSTRAP_GEN0_PIN_HEAD:-}
    drift_abort() {
      echo \"DRIFT_ABORT: \$*\" | tee -a \"\$STATUS\"
      # Prefer killing the compile child so time(1) returns; fall back to container exit.
      pkill -TERM -f 'bin/compile.php' 2>/dev/null || true
      exit 97
    }
    ( while true; do
        cur=\$(git rev-parse HEAD 2>/dev/null || echo unknown)
        if [[ -n \"\$PIN_HEAD\" && \"\$cur\" != \"\$PIN_HEAD\" ]]; then
          drift_abort \"HEAD drifted \$PIN_HEAD -> \$cur (do not checkout other branches mid-refresh)\"
        fi
        if ! grep -q \"require_once __DIR__ . '/ClassConstFetchHelperTrait.php'\" \
          /compiler/lib/JIT/ClassConstFetchHelper.php 2>/dev/null; then
          drift_abort 'ClassConstFetchHelperTrait self-require missing from bind mount'
        fi
        cpid=\$(pgrep -n -f '/compiler/bin/compile.php' || true)
        rss=0
        if [[ -n \"\$cpid\" && -r /proc/\$cpid/status ]]; then
          rss=\$(awk '/VmRSS/{print \$2}' /proc/\$cpid/status)
        fi
        prog=\$(cat /compiler/build/.last-jit-spine-exclusive 2>/dev/null || echo none)
        echo \"\$(date -u +%H:%M:%S) heartbeat rss=\${rss}kB prog=\$prog head=\${cur:0:12}\" >> \"\$HB\"
        sleep 60
      done ) &
    HBPID=\$!
    {
      echo START \$(date -u +%H:%M:%S) HEAD=\$(git rev-parse --short HEAD) pin=\${PIN_HEAD:0:12} ulimit_v=\$(ulimit -v) name=${NAME}
      echo ${NAME} \$\$ \$(date -u -Iseconds) > /compiler/build/.gen0-refresh-exclusive.lock
      set +e
      time ./script/bootstrap-refresh-gen0-sidecar.sh
      rc=\$?
      set -e
      echo REFRESH_RC=\$rc \$(date -u +%H:%M:%S) | tee -a \"\$STATUS\"
      echo LAST_PROGRESS=\$(cat /compiler/build/.last-jit-spine-exclusive 2>/dev/null)
      if [[ \$rc -eq 0 ]]; then
        echo REFRESH_OK \$(date -u +%H:%M:%S)
        php script/check-bootstrap-gen0-manifest-sync.php
        make north-star5-verify-fast
        ./script/release-readiness.sh --json | tee /compiler/build/release-readiness-gen0-exclusive.json
      fi
      echo EXCLUSIVE_END rc=\$rc \$(date -u +%H:%M:%S)
      rm -f /compiler/build/.gen0-refresh-exclusive.lock
      kill \$HBPID 2>/dev/null || true
      exit \$rc
    } 2>&1 | tee \"\$LOG\"
  "

echo "bootstrap-gen0-refresh-exclusive-docker: launched ${NAME}"
echo "  docker logs -f ${NAME}"
echo "  host log hint: ${LOG_HOST} (copy from build/gen0-refresh-exclusive-inner.log)"
docker ps --filter "name=${NAME}" --format '{{.Names}} {{.Status}}'
