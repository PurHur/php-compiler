<?php
/**
 * AOT int-path repro #26657 — oversized step hits LLVM ValueError guard then abort
 * (same shape as zero-step AOT; NestedJIT success-path with HELPER_RUNTIME_O=0 is separate).
 */
range(0, 1, 2);
echo "unreachable\n";
