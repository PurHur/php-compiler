--TEST--
Generator throw() injects exception at yield (#167, Zend zend_generators.c)
--FILE--
<?php
function gen(): Generator {
    try {
        yield 1;
    } catch (Exception $e) {
        yield 'caught: '.$e->getMessage();
    }
}
$g = gen();
$g->rewind();
echo $g->current(), "\n";
echo $g->throw(new Exception('boom')), "\n";
echo $g->current(), "\n";
--EXPECT--
1
caught: boom
caught: boom
