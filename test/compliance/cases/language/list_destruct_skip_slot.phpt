--TEST--
Language: list()/[] destructuring skip slots bind next RHS element (#10807, zend_compile.c)
--FILE--
<?php
declare(strict_types=1);

list(, $y) = [1, 2];
var_export($y);
echo "\n";

[, $y2] = [1, 2];
var_export($y2);
echo "\n";

[, $b, ] = [1, 2, 3];
var_export($b);
echo "\n";
--EXPECT--
2
2
2
