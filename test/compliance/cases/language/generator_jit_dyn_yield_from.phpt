--TEST--
Generator dynamic yield from variable via MCJIT (issue #3074)
--FILE--
<?php
function inner(): Generator {
    yield 1;
    yield 2;
}
function outer(): Generator {
    $g = inner();
    yield from $g;
    yield 3;
}
foreach (outer() as $v) {
    echo $v;
}
--EXPECT--
123