--TEST--
Generator valid() false after uncaught throw in generator body (#13019, Zend/zend_generators.c)
--FILE--
<?php
function gen(): Generator {
    yield 1;
    throw new Exception('x');
}
$g = gen();
$g->rewind();
try {
    $g->next();
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
}
echo $g->valid() ? "true\n" : "false\n";
--EXPECT--
x
false
