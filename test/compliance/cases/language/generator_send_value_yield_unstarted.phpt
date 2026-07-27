--TEST--
Generator::send() on unstarted value-yield opens, injects, and advances (#23712, Zend/zend_generators.c)
--FILE--
<?php
function g(): Generator {
    $v = yield 1;
    yield $v;
}
$g = g();
echo var_export($g->send('x'), true), "\n";
echo var_export($g->current(), true), "\n";
echo var_export($g->key(), true), "\n";
--EXPECT--
'x'
'x'
1
