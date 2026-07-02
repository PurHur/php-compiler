--TEST--
stdlib sprintf() %h/%H conversion specifiers (#9991, ext/standard/sprintf.c)
--FILE--
<?php
declare(strict_types=1);

echo sprintf('%h', 1.2), "\n";
echo sprintf('%H', 1.2), "\n";
echo sprintf('%h', 1234567.0), "\n";
echo sprintf('%H', 1234567.0), "\n";
echo sprintf('%h', 0.00001), "\n";
echo sprintf('%h', INF), "\n";
echo sprintf('%h', NAN), "\n";
echo sprintf('%h', -0.0), "\n";
--EXPECT--
1.2
1.2
1.23457e+6
1.23457E+6
1.0e-5
INF
NaN
-0
