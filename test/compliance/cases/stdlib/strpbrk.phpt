--TEST--
stdlib strpbrk()
--FILE--
<?php
echo strpbrk('hello', 'aeiou'), "\n";
echo strpbrk('test', 'st'), "\n";
echo strpbrk('path/to/file', '/'), "\n";
--EXPECT--
ello
test
/to/file
