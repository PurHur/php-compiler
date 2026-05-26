--TEST--
AOT: function-local static counter (issue #2286)
--FILE--
<?php
function f() {
    static $n = 0;
    $n++;
    return $n;
}
echo f(), f(), f();
--EXPECT--
123
