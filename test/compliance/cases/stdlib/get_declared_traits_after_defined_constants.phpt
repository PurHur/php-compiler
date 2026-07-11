--TEST--
Regression: get_declared_traits() in in_array() after get_defined_constants(true) (#15611, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

$c = get_defined_constants(true);
echo in_array('Traversable', get_declared_traits(), true) ? "true\n" : "false\n";

class CV { public static int $s = 1; }
--EXPECT--
false
