--TEST--
stdlib acosh()/asinh()/atanh() inverse hyperbolic (issue #9220, ext/standard/math.c)
--FILE--
<?php
foreach (['acosh', 'asinh', 'atanh'] as $f) {
    echo $f, ' exists? ';
    var_dump(function_exists($f));
}
var_dump(acosh(1.5), asinh(1.5), atanh(0.5));
echo intval(acosh(1.5) * 1000), "\n";
echo intval(asinh(1.5) * 1000), "\n";
echo intval(atanh(0.5) * 1000), "\n";
--EXPECT--
acosh exists? bool(true)
asinh exists? bool(true)
atanh exists? bool(true)
float(0.962423650119207)
float(1.1947632172871094)
float(0.5493061443340548)
962
1194
549
