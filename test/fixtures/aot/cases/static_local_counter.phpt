--TEST--
AOT: function-local static int counter (issue #2286)
--FILE--
<?php
function f(): int
{
    static $n = 0;
    $n = $n + 1;

    return $n;
}
echo f(), f(), f();
--EXPECT--
123
