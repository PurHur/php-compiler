--TEST--
Generator yield from nested generator via MCJIT (issue #3074)
--FILE--
<?php
function inner() {
    yield 1;
    yield 2;
}
function outer() {
    yield from inner();
    yield 3;
}
foreach (outer() as $v) {
    echo $v;
}
--EXPECT--
123
