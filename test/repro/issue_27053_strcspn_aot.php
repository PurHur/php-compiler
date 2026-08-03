<?php

declare(strict_types=1);

/**
 * Repro #27053 — AOT strcspn() must match Zend/VM (not silent 0).
 * Run: PHP_COMPILER_HELPER_RUNTIME_O=0 ./phpc build -o /tmp/aot_strcspn test/repro/issue_27053_strcspn_aot.php && /tmp/aot_strcspn
 */

echo strcspn('abcdef', 'd'), "\n";
echo strcspn('hello', 'l'), "\n";
$s = 'hello';
$m = 'l';
echo strcspn($s, $m), "\n";
