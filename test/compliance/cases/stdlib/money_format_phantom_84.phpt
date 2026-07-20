--TEST--
stdlib money_format() — not registered under PROFILE=8.4 (removed php-src 8.0, #21481)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo function_exists('money_format') ? "fail\n" : "ok\n";
--EXPECT--
ok
