--TEST--
Integration: pi, angle conversion, log, exp, and float classification
--FILE--
<?php
echo intval(rad2deg(deg2rad(90))), "\n";
echo intval(log(exp(2))), "\n";
echo is_finite(pi()) ? 'y' : 'n', "\n";
echo is_infinite(log(0)) ? 'y' : 'n', "\n";
--EXPECT--
90
2
y
y
