<?php

declare(strict_types=1);

/**
 * #36385 — {main} for-loop with strlen()/count() bound + ++/$i++ must terminate.
 *
 * Root cause: #36018 alloca materialization for named `$i++` skipped isMainScript(),
 * so JUMPIF kept reading a stale KIND_VALUE i64 0 (k-nucleotide AOT hang).
 *
 * php-src: Zend/zend_vm_def.h ZEND_PRE_INC / ZEND_POST_INC; Zend/zend_execute.c
 * for-loop JUMPIF on IS_LONG counter.
 */
$n = strlen('ACAC');
$x = 0;
for ($i = 0; $i < $n; ++$i) {
    $x++;
}
echo $x, "\n";

$m = count([1, 2, 3]);
$y = 0;
for ($j = 0; $j < $m; $j++) {
    $y++;
}
echo $y, "\n";
