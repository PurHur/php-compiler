--TEST--
stdlib json_validate() — withheld on PHP 8.2 profile (#11826, #19951, #22544)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
echo function_exists('json_validate') ? "fail\n" : "ok\n";
--EXPECT--
ok
