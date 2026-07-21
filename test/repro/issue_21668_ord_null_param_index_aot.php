<?php
/**
 * AOT repro #21668 — ord soft-null return 0 (DEP text guarded on VM/JIT).
 *
 * PHP_COMPILER_PROFILE=8.4 ./phpc build -o /tmp/i21668 \
 *   test/repro/issue_21668_ord_null_param_index_aot.php && /tmp/i21668
 */
$character = null;
echo ord($character), "\n";
