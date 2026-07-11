--TEST--
stdlib HTTP_TOO_EARLY constant — not advertised on PHP 8.2 reference profile (#18059)
--FILE--
<?php
echo defined('HTTP_TOO_EARLY') ? "fail\n" : "ok\n";
--EXPECT--
ok
