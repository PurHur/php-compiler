--TEST--
Stdlib: constant() accepts leading backslash global names (#12190, basic_functions.c)
--FILE--
<?php
define('GAP_CONST', 42);
echo constant('\\GAP_CONST'), "\n";
echo defined('\\GAP_CONST') ? "defined-ok\n" : "defined-bad\n";
echo constant('\\PHP_VERSION') !== '' ? "php-version-ok\n" : "php-version-bad\n";
--EXPECT--
42
defined-ok
php-version-ok
