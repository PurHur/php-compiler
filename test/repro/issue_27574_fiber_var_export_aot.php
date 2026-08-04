<?php

/**
 * #27574 — AOT Fiber::suspend/resume/getReturn via var_export (thin scalar bridge).
 * Crash was NestedJIT addslashes under thin AOT; Fiber transfer itself matched Zend.
 */
$f = new Fiber(function () {
    $v = Fiber::suspend('suspended');

    return "done:$v";
});
var_export($f->start());
echo "\n";
var_export($f->resume('X'));
echo "\n";
var_export($f->getReturn());
echo "\n";
