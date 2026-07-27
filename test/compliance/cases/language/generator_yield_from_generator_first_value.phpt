--TEST--
Generator yield from nested generator preserves first delegated value (issue #23813)
--FILE--
<?php
function inner() {
    yield 10;
}
function outer() {
    yield from inner();
    yield 20;
}
foreach (outer() as $v) {
    echo "$v\n";
}
--EXPECT--
10
20
