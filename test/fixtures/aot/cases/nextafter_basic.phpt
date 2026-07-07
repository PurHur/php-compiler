--TEST--
AOT: nextafter() IEEE next representable float (#9241, ext/standard/math.c)
--FILE--
<?php
declare(strict_types=1);

// var_export(float) aborts in user-script AOT (#17279); ordering checks IEEE neighbors.
echo (nextafter(1.0, 2.0) > 1.0) ? "1\n" : "0\n";
echo (nextafter(1.0, 0.0) < 1.0) ? "1\n" : "0\n";
echo (nextafter(0.0, 1.0) > 0.0) ? "1\n" : "0\n";
--EXPECT--
1
1
1
