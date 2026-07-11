--TEST--
Generator::current() returns first yield before throw on next() (#13989, Zend/zend_generators.c)
--FILE--
<?php
function g(): Generator {
    yield 1;
    throw new Exception('x');
}
$g = g();
echo $g->current(), "\n";
try {
    $g->next();
    echo "fail\n";
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
1
x
