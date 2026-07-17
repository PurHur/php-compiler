--TEST--
AOT: mb_stristr()/mb_strrchr()/mb_strripos() multibyte search (#20006)
--FILE--
<?php
echo mb_stristr('Hello World', 'WORLD'), "\n";
echo mb_strrchr('Hello World', 'o'), "\n";
echo mb_strripos('Hello World', 'L'), "\n";
--EXPECT--
World
orld
9
