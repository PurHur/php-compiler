--TEST--
stdlib ctype_blank() — not in php-src ext/ctype (#21459, re-#3381, ext/ctype/ctype.c)
--FILE--
<?php
echo function_exists('ctype_blank') ? "fail\n" : "ok\n";
--EXPECT--
ok
