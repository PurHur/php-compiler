--TEST--
AOT: instanceof with function-arg class name string (#32775)
--FILE--
<?php
class A {}
function check($o, $n) {
    var_dump($o instanceof $n);
}
check(new A(), 'A');
check(new stdClass(), 'stdClass');
$n = 'A';
var_dump((new A()) instanceof $n);
--EXPECT--
bool(true)
bool(true)
bool(true)
--EXPECT_EXIT--
0
