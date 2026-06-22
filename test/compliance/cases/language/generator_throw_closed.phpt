--TEST--
Generator throw() on closed generator propagates to caller (#10414, Zend/zend_generators.c)
--FILE--
<?php
$g = (function () {
    yield 1;
})();
$g->next();
try {
    $g->throw(new Exception('x'));
    echo "no\n";
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
}
echo $g->valid() ? "valid\n" : "closed\n";
--EXPECT--
x
closed
