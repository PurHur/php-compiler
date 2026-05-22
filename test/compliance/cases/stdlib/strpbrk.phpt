--TEST--
stdlib strpbrk()
--FILE--
<?php
echo strpbrk('abc123', '123'), "\n";
echo strpbrk('123abc', '123'), "\n";
echo strpbrk('no-match', 'z') === false ? "0\n" : "1\n";
--EXPECT--
123
123abc
0
