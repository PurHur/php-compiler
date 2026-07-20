--TEST--
mbstring mb_ucwords() — not advertised on any profile (Zend never ships; #21458 / #20799)
--FILE--
<?php
echo function_exists('mb_ucwords') ? "fail\n" : "ok\n";
--EXPECT--
ok
