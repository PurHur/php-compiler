--TEST--
stdlib PHP_BUILD_DATE Core constant — not advertised on PHP 8.2 reference profile (#23231)
--FILE--
<?php
echo defined('PHP_BUILD_DATE') ? "fail\n" : "ok\n";
--EXPECT--
ok
