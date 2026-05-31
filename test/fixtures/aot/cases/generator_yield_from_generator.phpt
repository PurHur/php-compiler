--TEST--
AOT: generator yield from inner then yield (issue #3115, Zend/zend_generators.c)
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
