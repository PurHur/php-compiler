--TEST--
mbstring mb_ucwords() — UTF-8 title case per word (#20799, ext/mbstring/mbstring.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo mb_ucwords('hello world'), "\n";
echo mb_ucwords(''), "\n";
echo mb_ucwords('ßtraße test'), "\n";
echo mb_convert_case('hello world', MB_CASE_TITLE, 'UTF-8'), "\n";
--EXPECT--
Hello World

SStraße Test
Hello World
