--TEST--
AOT: Generator::throw() into try/catch that does not yield again (#33726, re-#27518, Zend/zend_generators.c)
--FILE--
<?php
function g() {
    try {
        yield 1;
    } catch (Exception $e) {
        echo 'C' . $e->getMessage();
    }
}
$g = g();
$g->current();
$g->throw(new Exception('x'));
echo "\n";
--EXPECT--
Cx
