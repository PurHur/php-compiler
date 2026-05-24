--TEST--
Variable function call via string variable (issue #56)
--FILE--
<?php
$fn = 'strlen';
echo $fn('hi'), "\n";
function greet() {
    return 'hello';
}
$call = 'greet';
echo $call(), "\n";
--EXPECT--
2
hello
