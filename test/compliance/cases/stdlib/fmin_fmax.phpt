--TEST--
fmin()/fmax() variadic float min/max (PHP 8.4, issue #11728)
--FILE--
<?php
echo function_exists('fmin') ? '1' : '0', "\n";
echo function_exists('fmax') ? '1' : '0', "\n";
echo fmin(1.5, 2.0, 0.5), "\n";
echo fmax(1.5, 2.0, 3.0), "\n";
echo fmin(1.0, NAN), "\n";
echo fmax(1.0, NAN), "\n";
try {
    fmin(1.0);
    echo "no_error\n";
} catch (ArgumentCountError $e) {
    echo "arg_error\n";
}
?>
--EXPECT--
1
1
0.5
3
1
1
arg_error
