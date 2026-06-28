--TEST--
Generator getReturn() after throw without return (issue #13027, Zend/zend_generators.c)
--FILE--
<?php
function genThrow(): Generator {
    yield 1;
    throw new Exception('x');
}
$g = genThrow();
$g->rewind();
try {
    $g->next();
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
}
try {
    $g->getReturn();
    echo "no\n";
} catch (Exception $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo $g->valid() ? "valid\n" : "closed\n";
--EXPECT--
x
Exception: Cannot get return value of a generator that hasn't returned
closed
