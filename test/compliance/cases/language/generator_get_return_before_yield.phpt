--TEST--
Generator getReturn() on return-before-yield without iteration (issue #9007)
--FILE--
<?php
function g(): Generator {
    return 1;
    yield;
}
$g = g();
var_dump($g->getReturn());
var_dump($g->valid());
--EXPECT--
int(1)
bool(false)
