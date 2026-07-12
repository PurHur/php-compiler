--TEST--
Generator bare-yield send+current on second instance after first (#18184, Zend/zend_generators.c)
--FILE--
<?php
function g(): Generator {
    $x = yield;
    yield $x * 2;
}
$first = g();
$first->send(3);
echo var_export($first->current(), true), "\n";
$second = g();
$second->rewind();
$second->send(3);
echo var_export($second->current(), true), "\n";
--EXPECT--
6
6
