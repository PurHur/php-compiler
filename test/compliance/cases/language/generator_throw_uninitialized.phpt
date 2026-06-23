--TEST--
Generator throw() before first yield propagates to caller (#10721, Zend/zend_generators.c)
--FILE--
<?php
function gen(): Generator {
    yield 1;
}
$gen = gen();
try {
    $gen->throw(new Exception('x'));
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
}
echo $gen->valid() ? 'true' : 'false', "\n";
--EXPECT--
x
false
