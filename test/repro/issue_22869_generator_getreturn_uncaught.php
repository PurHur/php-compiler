<?php

/**
 * Issue #22869 — uncaught Generator::getReturn() after throw→yield-in-catch must fatal
 * with non-zero exit (Zend/zend_generators.c), not silent exit 0.
 */
function gen() {
    try {
        yield 1;
    } catch (Exception $e) {
        yield 2;
    }
    return 3;
}
$g = gen();
echo "A\n";
echo $g->current(), "\n";
echo "B\n";
echo $g->throw(new Exception('x')), "\n";
echo "C\n";
$r = $g->getReturn();
echo "D\n";
var_dump($r);
