<?php
/**
 * Issue #23540 — AOT var_dump/print_r must match Zend (not SIGABRT).
 *
 * Build: ./phpc build -o /tmp/vd test/repro/issue_23540_aot_var_dump.php && /tmp/vd
 */
echo "BEFORE\n";
$a = 7;
var_dump($a);
$b = 1.5;
var_dump($b);
print_r([1, 2]);
echo "\nAFTER\n";
