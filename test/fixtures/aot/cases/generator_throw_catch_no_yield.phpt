--TEST--
AOT: Generator::throw into catch without further yield (issue #33726, Zend/zend_generators.c)
--FILE--
<?php
function g() {
    try {
        yield 1;
    } catch (Exception $e) {
        echo 'C'.$e->getMessage(), "\n";
    }
}
$gen = g();
$gen->current();
$gen->throw(new Exception('x'));
--EXPECT--
Cx
