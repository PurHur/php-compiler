--TEST--
stdlib printf()/fprintf() %f — NAN/INF casing matches Zend (issue #10151)
--FILE--
<?php
printf("%f\n", NAN);
printf("%f %f\n", NAN, INF);

$f = fopen('php://memory', 'w+');
fprintf($f, '%f', NAN);
rewind($f);
echo stream_get_contents($f);
--EXPECT--
NaN
NaN INF
NaN
