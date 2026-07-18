--TEST--
stdlib hebrevc() — not registered under PROFILE=8.4 (removed php-src 8.0, #20354)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo function_exists('hebrevc') ? "fail\n" : "ok\n";
echo function_exists('hebrev') ? "hebrev-yes\n" : "hebrev-no\n";
--EXPECT--
ok
hebrev-yes
