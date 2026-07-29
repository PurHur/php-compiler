<?php

/**
 * Repro #24598 — case/shuffle/repeat soft-null under PROFILE=8.4 (php-src-strict).
 *
 * VM:  PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_24598_case_repeat_null_soft.php
 * AOT: PHP_COMPILER_PROFILE=8.4 php bin/compile.php -o /tmp/case_repeat_null84 test/repro/issue_24598_case_repeat_null_soft.php && /tmp/case_repeat_null84
 *
 * Prefer assignment + strlen over `=== ''` ternary on the same expression — AOT has a
 * pre-existing crash comparing some soft-null results inline (#24598 verification).
 */

$fail = 0;
$u = ucfirst(null);
$l = lcfirst(null);
$w = ucwords(null);
$s = str_shuffle(null);
$r = str_repeat(null, 1);
foreach (
    [
        'ucfirst' => $u,
        'lcfirst' => $l,
        'ucwords' => $w,
        'str_shuffle' => $s,
        'str_repeat' => $r,
    ] as $name => $val
) {
    if ('' === $val) {
        echo "PASS: $name\n";
    } else {
        echo "FAIL: $name -> ", var_export($val, true), "\n";
        ++$fail;
    }
}
exit($fail > 0 ? 1 : 0);
