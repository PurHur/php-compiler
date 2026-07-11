--TEST--
Generator throw immediately after yield on same resume (#13366, Zend/zend_generators.c)
--FILE--
<?php
function g(): Generator {
    yield 1;
    throw new Exception('x');
}
$g = g();
echo "step1\n";
$g->next();
echo "step2\n";
--EXPECT_EXIT--
255
--EXPECT--
step1
