--TEST--
stdlib preg_match() octal \0 escape matches embedded NUL (#13552, ext/pcre/php_pcre.c)
--FILE--
<?php
echo preg_match('/\0/', "a\0b") ? '1' : '0', "\n";
preg_match('/(.*)\0(.*)/', "a\0b", $m);
echo count($m), "\n";
echo $m[1], ':', $m[2], "\n";
--EXPECT--
1
3
a:b
