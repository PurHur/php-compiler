--TEST--
stdlib TENTATIVE_RETURN Core constant — not advertised on PHP 8.2 reference profile (#18060)
--FILE--
<?php
echo defined('TENTATIVE_RETURN') ? "fail\n" : "ok\n";
--EXPECT--
ok
