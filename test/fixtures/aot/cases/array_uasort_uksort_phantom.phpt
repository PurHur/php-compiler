--TEST--
AOT: array_uasort()/array_uksort() — not in php-src (#22372)
--FILE--
<?php
echo function_exists('array_uasort') ? "fail\n" : "ok\n";
echo function_exists('array_uksort') ? "fail\n" : "ok\n";
--EXPECT--
ok
ok
