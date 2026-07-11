<?php

/**
 * Issue #17871 — preg_replace() null $replacement deletes matches (ext/pcre/php_pcre.c).
 */

$result = preg_replace('/a/', null, 'abc');
if ('bc' !== $result) {
    echo 'fail: expected bc, got ', var_export($result, true), "\n";
    exit(1);
}

$count = -1;
$result2 = preg_replace('/a/', null, 'abc', -1, $count);
if ('bc' !== $result2 || 1 !== $count) {
    echo 'fail: count=', var_export($count, true), ' result=', var_export($result2, true), "\n";
    exit(1);
}

echo "ok\n";
