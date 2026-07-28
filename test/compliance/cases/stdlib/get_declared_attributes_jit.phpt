--TEST--
stdlib get_declared_attributes() — not in php-src (JIT, #24222, re-#6450)
--JIT--
--FILE--
<?php
echo function_exists('get_declared_attributes') ? "fail\n" : "ok\n";
--EXPECT--
ok
