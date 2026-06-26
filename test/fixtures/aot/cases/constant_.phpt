--TEST--
AOT: constant() — user and core constants (issue #3813)
--FILE--
<?php
define('MY_CONST', 42);
echo constant('MY_CONST'), "\n";
echo constant('\\MY_CONST'), "\n";
echo constant('PHP_INT_MAX'), "\n";
echo function_exists('constant') ? "exists_ok\n" : "exists_bad\n";
--EXPECT--
42
42
9223372036854775807
exists_ok
