--TEST--
Generator::current() idempotent after rewind+send on bare-yield (#18183, Zend/zend_generators.c)
--FILE--
<?php
function g(): Generator {
    $x = yield;
    yield $x * 2;
}
$g = g();
$g->rewind();
$g->send(3);
echo $g->current(), "\n";
echo $g->current(), "\n";
--EXPECT--
6
6
