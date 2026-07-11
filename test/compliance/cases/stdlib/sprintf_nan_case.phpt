--TEST--
stdlib sprintf() %F/%G/%E/%f non-finite NaN token (#12216, ext/standard/sprintf.c)
--FILE--
<?php
echo sprintf('%F', NAN), "\n";
echo sprintf('%G', NAN), "\n";
echo sprintf('%E', NAN), "\n";
echo sprintf('%f', NAN), "\n";
echo sprintf('%F', INF), "\n";
--EXPECT--
NaN
NaN
NaN
NaN
INF
