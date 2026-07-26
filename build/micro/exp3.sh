#!/bin/bash
export PHP_COMPILER_LLVM_PATH=/opt/llvm9; export LD_LIBRARY_PATH=/opt/llvm9
cd /app; : > build/micro/exp3-results.txt
for L in 2 3; do
  T=/tmp/e3_O$L
  if PHP_COMPILER_DUMP_IR=1 env PHP_COMPILER_OPT_LEVEL=$L PHPC_EXP_GUARDED_INCDEC=1 PHP_COMPILER_DUMP_IR=1 \
     PHP_COMPILER_LLVM_PATH=/opt/llvm9 LD_LIBRARY_PATH=/opt/llvm9 ./phpc build -o "$T" build/micro/m1_loop.php >/tmp/e3err 2>&1; then
    cp /tmp/phpc-last.ll build/micro/exp3-O$L.ll 2>/dev/null
    out=$("$T" 2>&1 | head -1)
    s=$(date +%s%N); for i in 1 2 3; do "$T" >/dev/null 2>&1; done; e=$(date +%s%N)
    # did LLVM hoist the guard out of the loop (loop unswitching)?
    fast=$(grep -c 'incdec_long_fast' build/micro/exp3-O$L.ll 2>/dev/null)
    echo "RESULT guarded_O$L out=$out time=$(( (e-s)/3000000 ))ms fast_blocks_in_ir=$fast" >> build/micro/exp3-results.txt
  else
    echo "RESULT guarded_O$L BUILD_FAILED $(grep -iE 'error|fatal' /tmp/e3err | head -1 | cut -c1-90)" >> build/micro/exp3-results.txt
  fi
done
echo "EXP3_DONE" >> build/micro/exp3-results.txt
