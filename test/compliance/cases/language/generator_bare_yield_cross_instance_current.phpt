--TEST--
Generator bare-yield send on first instance — second generator current() after rewind+send (#18184, Zend/zend_generators.c)
--FILE--
<?php
function g(): Generator {
    $x = yield;
    yield $x * 2;
}
$g = g();
$g->send(3);
$g2 = g();
$g2->rewind();
$g2->send(3);
echo var_export($g2->current(), true), "\n";
--EXPECT--
6
