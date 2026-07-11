--TEST--
stdlib nextafter() withheld on 8.2 reference profile (#15677, ext/standard/math.c)
--FILE--
<?php
declare(strict_types=1);

echo function_exists('nextafter') ? "exists\n" : "ok\n";
?>
--EXPECT--
ok
