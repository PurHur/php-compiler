--TEST--
AOT: Fiber suspend/resume/getReturn via var_export (#27574, Zend/zend_fibers.c)
--FILE--
<?php
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
--EXPECT--
'suspended'
NULL
'done:X'
