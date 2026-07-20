--TEST--
mbstring mb_ucwords() — phantom on PHP_COMPILER_PROFILE=8.4 (Zend never ships; #21458)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo function_exists('mb_ucwords') ? "fail\n" : "ok\n";
--EXPECT--
ok
