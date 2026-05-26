--TEST--
function-local static string persists across calls (issue #2286)
--FILE--
<?php
function tag(): string
{
    static $s = 'a';
    $s .= 'b';

    return $s;
}
echo tag(), "\n";
echo tag(), "\n";
--EXPECT--
ab
abb
