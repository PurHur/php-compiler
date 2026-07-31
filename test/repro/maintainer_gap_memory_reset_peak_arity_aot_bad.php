<?php
/**
 * AOT uncaught wrong-arity probe for #26104 (try/catch+AndAbort still LLVM-verify-broken, peer sort()).
 *
 * php bin/compile.php -o /tmp/mrpu_bad test/repro/maintainer_gap_memory_reset_peak_arity_aot_bad.php
 * /tmp/mrpu_bad ; echo exit:$?
 * Expect: Uncaught ArgumentCountError, non-zero exit (not should_not_reach).
 */
memory_reset_peak_usage(true);
echo "should_not_reach\n";
