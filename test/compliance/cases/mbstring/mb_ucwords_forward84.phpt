--TEST--
mbstring mb_ucwords() — title case words on PHP_COMPILER_PROFILE=8.4 (#20799, ext/mbstring/mbstring.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo function_exists('mb_ucwords') ? '1' : '0';
echo "\n";
echo mb_ucwords('hello world'), "\n";
echo mb_ucwords('über alles'), "\n";
echo mb_ucwords(''), "\n";
--EXPECT--
1
Hello World
Über Alles

