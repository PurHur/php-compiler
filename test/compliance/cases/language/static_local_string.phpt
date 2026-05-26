--TEST--
Function-local static string (issue #2286)
--FILE--
<?php
function tag(): string
{
    static $s = 'a';
    $s .= 'b';

    return $s;
}
echo tag(), "\n", tag(), "\n";
--EXPECT--
ab
abb
