--TEST--
AOT: mb_stripos()/mb_strrpos()/mb_strrichr() multibyte search (#7015)
--FILE--
<?php
echo mb_stripos('Hello World', 'world'), "\n";
echo mb_strrpos('Hello World', 'o'), "\n";
echo mb_strrichr('Hello World', 'WORLD'), "\n";
--EXPECT--
6
7
World
