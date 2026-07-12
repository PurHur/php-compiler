--TEST--
Generator::current() idempotent in var_export after bare-yield send (#18183, Zend/zend_generators.c)
--FILE--
<?php
function g(): Generator {
    $x = yield;
    yield $x * 2;
}
$g = g();
$g->send(3);
echo var_export($g->current(), true), "\n";
echo var_export($g->current(), true), "\n";
--EXPECT--
6
6
