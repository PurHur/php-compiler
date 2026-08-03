<?php
/**
 * Repro #27157 — AOT gmdate composite format must not segfault.
 *
 *   PHP_COMPILER_HELPER_RUNTIME_O=0 ./phpc build -o /tmp/gm test/repro/issue_27157_aot_gmdate.php
 *   /tmp/gm
 */
echo gmdate('Y-m-d H:i:s', 0), "\n";
echo gmdate('c', 0), "\n";
