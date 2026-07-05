--TEST--
Generator::next() throw propagates through try when followed by fwrite() (#16610, Zend/zend_generators.c)
--FILE--
<?php
function g(): Generator {
    yield 1;
    throw new Exception('x');
}
$g = g();
$g->current();
try {
    $g->next();
    fwrite(STDERR, "no\n");
    echo "fail\n";
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
x
