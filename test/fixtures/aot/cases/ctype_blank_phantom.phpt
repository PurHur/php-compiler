--TEST--
AOT: ctype_blank() — not in php-src (#21459)
--FILE--
<?php
echo function_exists('ctype_blank') ? "fail\n" : "ok\n";
--EXPECT--
ok
