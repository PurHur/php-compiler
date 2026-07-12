--TEST--
Generator::current() on second instance after bare-yield send on first (#18184, Zend/zend_generators.c)
--FILE--
<?php
declare(strict_types=1);

function g(): Generator {
    $x = yield;
    yield $x * 2;
}

$first = g();
$first->send(3);
echo 'first=', var_export($first->current(), true), "\n";

$second = g();
$second->rewind();
$second->send(3);
echo 'second=', var_export($second->current(), true), "\n";
--EXPECT--
first=6
second=6
