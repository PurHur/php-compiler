--TEST--
stdlib stream_copy_to_string() — not in php-src (#23201, re-#6547, ext/standard/streamsfuncs.c)
--FILE--
<?php
echo function_exists('stream_copy_to_string') ? "fail\n" : "ok\n";
--EXPECT--
ok
