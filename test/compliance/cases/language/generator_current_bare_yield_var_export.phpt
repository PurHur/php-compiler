--TEST--
Generator::current() idempotent after bare-yield send when nested in var_export (#18183, Zend/zend_generators.c)
--FILE--
<?php
declare(strict_types=1);

function g(): Generator {
    $x = yield;
    yield $x * 2;
}

$g = g();
$g->send(3);
echo var_export($g->current(), true), "\n";

$g2 = g();
$g2->rewind();
$g2->send(3);
echo var_export($g2->current(), true), "\n";
--EXPECT--
6
6
