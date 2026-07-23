--TEST--
stdlib crc32c() — not advertised on PHP 8.2 reference profile (#18020, #22584, ext/standard/crc32.c)
--FILE--
<?php
echo function_exists('crc32c') ? "fail\n" : "ok\n";
--EXPECT--
ok
