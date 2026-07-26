<?php
/**
 * Issue #23540 — AOT var_dump(int|float) must match Zend (not SIGABRT).
 *
 * Done-when: var_dump(7) / var_dump(1.5). Array print_r still needs Runtime->vm.
 *
 * Build: PHP_COMPILER_HELPER_RUNTIME_O=0 ./phpc build -o /tmp/vd test/repro/issue_23540_aot_var_dump.php && /tmp/vd
 */
echo "BEFORE\n";
$a = 7;
var_dump($a);
$b = 1.5;
var_dump($b);
echo "AFTER\n";
