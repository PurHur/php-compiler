--TEST--
stdlib mb_stristr()/mb_strrchr()/mb_strripos() JIT compile-time fold (#20006)
--FILE--
<?php
echo mb_stristr('Hello World', 'WORLD'), "\n";
echo mb_strrchr('Hello World', 'o'), "\n";
echo mb_strripos('Hello World', 'L', 0, 'UTF-8'), "\n";
--EXPECT--
World
orld
9
