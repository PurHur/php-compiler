--TEST--
Generator::current() before send() primes yield — no global current() by-ref fatal (#10610, Zend/zend_generators.c)
--FILE--
<?php
function g(): Generator {
    $x = yield;
    echo "got={$x}\n";
}
$gen = g();
$gen->current();
$gen->send(42);
--EXPECT--
got=42
