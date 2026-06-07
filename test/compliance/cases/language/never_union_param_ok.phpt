--TEST--
Language: never in parameter union — compiles (PHP 8.2+, #7414)
--FILE--
<?php
function f(int|never $x): int {
    return $x;
}
echo f(3);
--EXPECT--
3
