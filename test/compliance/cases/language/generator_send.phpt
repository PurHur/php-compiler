--TEST--
Generator send() injects value into yield expression (#167, Zend zend_generators.c)
--FILE--
<?php
function gen(): Generator {
    $x = yield 1;
    return $x + 1;
}
$g = gen();
$g->rewind();
echo $g->current(), "\n";
$g->send(41);
echo $g->getReturn(), "\n";
--EXPECT--
1
42
