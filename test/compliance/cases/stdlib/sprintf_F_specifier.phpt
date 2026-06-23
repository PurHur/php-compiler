--TEST--
stdlib sprintf() %F conversion specifier (#9043, ext/standard/sprintf.c)
--FILE--
<?php
echo sprintf('%F', 1.2), "\n";
echo vsprintf('%F', [1.2]), "\n";
echo sprintf('%.2F', 3.5), "\n";
echo sprintf('%F', INF), "\n";
echo sprintf('%F', NAN), "\n";
echo sprintf('%F', -0.0), "\n";
--EXPECT--
1.200000
1.200000
3.50
INF
NaN
0.000000
