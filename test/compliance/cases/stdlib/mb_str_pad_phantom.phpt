--TEST--
stdlib mb_str_pad() — withheld on PHP 8.2 profile (#11964, #21790)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
echo function_exists('mb_str_pad') ? "fail\n" : "ok\n";
--EXPECT--
ok
