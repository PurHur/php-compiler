--TEST--
inc/dec bool and null -- emit Zend "has no effect" E_WARNING under PROFILE=8.4 (#26378)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $n, string $s): bool {
    echo 'W:', $s, "\n";
    return true;
});
$b = null;
$b--;
var_export($b);
echo "\n";
$f = false;
$f++;
var_export($f);
echo "\n";
$t = true;
$t--;
var_export($t);
echo "\n";
$a = null;
$a++;
var_export($a);
echo "\n";
$x = false;
$x--;
var_export($x);
echo "\n";
$y = true;
$y++;
var_export($y);
echo "\n";
--EXPECT--
W:Decrement on type null has no effect, this will change in the next major version of PHP
NULL
W:Increment on type bool has no effect, this will change in the next major version of PHP
false
W:Decrement on type bool has no effect, this will change in the next major version of PHP
true
1
W:Decrement on type bool has no effect, this will change in the next major version of PHP
false
W:Increment on type bool has no effect, this will change in the next major version of PHP
true
