#!/bin/bash
# Full VMTest baseline: all shards, sequentially, in ONE container (concurrent containers sharing
# the bind mount corrupt each other's caches). Writes per-shard .failed as it goes, so a kill or a
# stall still leaves usable partial results.
export PHP_COMPILER_LLVM_PATH=/opt/llvm9 LD_LIBRARY_PATH=/opt/llvm9
cd /app
R=build/micro/baseline-progress.txt
: > $R
SHARDS=24
date -u +"start %H:%M:%S" >> $R
for i in $(seq 0 $((SHARDS-1))); do
  s=$(date +%s)
  SHARD_TIMEOUT=900 script/shard-compliance.sh --suite=VMTest --shards=$SHARDS --shard=$i > /tmp/shard-$i.out 2>&1
  rc=$?
  ex=$(grep -oE "[0-9]+ executed" /tmp/shard-$i.out | grep -oE "[0-9]+" | head -1)
  fa=$(grep -oE "[0-9]+ failed"   /tmp/shard-$i.out | grep -oE "[0-9]+" | head -1)
  printf "shard %2d/%d rc=%s executed=%s failed=%s wall=%ss\n" "$i" "$SHARDS" "$rc" "${ex:-?}" "${fa:-?}" "$(( $(date +%s) - s ))" >> $R
done
date -u +"end   %H:%M:%S" >> $R
echo "BASELINE_DONE" >> $R
