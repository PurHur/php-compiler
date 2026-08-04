--TEST--
AOT: Generator::throw() into try/catch+yield (issue #27518, Zend/zend_generators.c)
--FILE--
<?php
function g() {
    try {
        yield 1;
        yield 2;
    } catch (Throwable $e) {
        yield 'caught:' . $e->getMessage();
    }
}
$gen = g();
echo $gen->current(), "\n";
echo $gen->throw(new Exception('x')), "\n";
--EXPECT--
1
caught:x
