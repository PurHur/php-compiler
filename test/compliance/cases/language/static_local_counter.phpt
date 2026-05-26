--TEST--
function-local static int counter persists across calls (issue #2286)
--FILE--
<?php
function f(): int
{
    static $n = 0;
    $n++;

    return $n;
}
echo f(), f(), f(), "\n";
--EXPECT--
123
