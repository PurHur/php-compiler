--TEST--
stdlib fadd()/fsub()/fmul() — IEEE float ops (PHP 8.4, ext/standard/math.c, #17290)
--FILE--
<?php
echo function_exists('fadd') ? '1' : '0', "\n";
echo function_exists('fsub') ? '1' : '0', "\n";
echo function_exists('fmul') ? '1' : '0', "\n";
echo fadd(0.1, 0.2), "\n";
echo fsub(1.0, 0.3), "\n";
echo fmul(2.0, 3.0), "\n";
echo is_nan(fadd(INF, -INF)) ? "nan\n" : "no\n";
try {
    fadd(1.0);
    echo "no_error\n";
} catch (ArgumentCountError $e) {
    echo "arg_error\n";
}
?>
--EXPECT--
1
1
1
0.3
0.7
6
nan
arg_error
