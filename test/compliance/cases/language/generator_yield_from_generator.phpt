--TEST--
Generator yield from another generator (issue #167)
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
echo "\n";
--EXPECT--
123
