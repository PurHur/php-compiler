--TEST--
Generator::send() on bare yield without rewind returns next yielded value (#18108, Zend/zend_generators.c)
--FILE--
<?php
function g(): Generator {
    $x = yield;
    yield $x * 2;
}
$g = g();
echo var_export($g->send(3), true), "\n";
echo var_export($g->current(), true), "\n";
--EXPECT--
6
6
