--TEST--
AOT: var_dump() of comparison results prints bool, not int (#23809)
--FILE--
<?php
$x = 5;
function f($p, $q) {
    var_dump($p, $q);
}
f($x > 3, $x < 3);
f($x === 5, $x !== 5);
--EXPECT--
bool(true)
bool(false)
bool(true)
bool(false)
