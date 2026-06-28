<?php
/**
 * Maintainer repro #13138 — foreach() + array_pop() during iteration (Zend/zend_execute.c).
 */
$a = [1, 2, 3];
foreach ($a as $v) {
    array_pop($a);
}
echo count($a) === 0 ? "ok\n" : 'fail count='.count($a)."\n";
