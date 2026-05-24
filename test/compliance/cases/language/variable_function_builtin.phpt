--TEST--
Variable function call to builtin ($fn = 'strlen') (issue #56)
--FILE--
<?php
$fn = 'strlen';
echo $fn('hi'), "\n";

function greet(string $name): string
{
    return 'hi '.$name;
}
$g = 'greet';
echo $g('bob'), "\n";
--EXPECT--
2
hi bob
