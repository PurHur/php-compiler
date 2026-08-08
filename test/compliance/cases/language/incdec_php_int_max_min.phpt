--TEST--
Language: ++/-- on PHP_INT_MAX/MIN promotes to float; typed int property TypeErrors (#29144)
--FILE--
<?php
$x = PHP_INT_MAX;
$x++;
echo gettype($x), "\n";
var_export($x);
echo "\n";

$x = PHP_INT_MAX;
++$x;
echo gettype($x), "\n";
var_export($x);
echo "\n";

$y = PHP_INT_MIN;
$y--;
echo gettype($y), "\n";
var_export($y);
echo "\n";

$y = PHP_INT_MIN;
--$y;
echo gettype($y), "\n";
var_export($y);
echo "\n";

$z = 7;
$z++;
echo gettype($z), " ", $z, "\n";

class C {
    public int $x;
}
$c = new C;
$c->x = PHP_INT_MAX;
try {
    $c->x++;
    echo "typed-inc-fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
echo $c->x === PHP_INT_MAX ? "typed-kept-max\n" : "typed-changed\n";

$c->x = PHP_INT_MIN;
try {
    $c->x--;
    echo "typed-dec-fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
echo $c->x === PHP_INT_MIN ? "typed-kept-min\n" : "typed-changed\n";
?>
--EXPECT--
double
9.223372036854776E+18
double
9.223372036854776E+18
double
-9.223372036854776E+18
double
-9.223372036854776E+18
integer 8
Cannot increment property C::$x of type int past its maximal value
typed-kept-max
Cannot decrement property C::$x of type int past its minimal value
typed-kept-min
