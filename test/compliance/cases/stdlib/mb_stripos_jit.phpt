--TEST--
stdlib mb_stripos()/mb_strrpos()/mb_strrichr() JIT compile-time fold (#7015)
--FILE--
<?php
echo mb_stripos('Hello World', 'world', 0, 'UTF-8'), "\n";
echo mb_strrpos('Hello World', 'o', 0, 'UTF-8'), "\n";
echo mb_strrichr('Hello World', 'WORLD'), "\n";
--EXPECT--
6
7
World
