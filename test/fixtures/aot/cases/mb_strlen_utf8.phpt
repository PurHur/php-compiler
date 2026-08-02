--TEST--
AOT: mb_strlen UTF-8 character count (#27051)
--FILE--
<?php
$s = "éclair";
echo mb_strlen("éclair", 'UTF-8'), PHP_EOL;
echo mb_strlen($s), PHP_EOL;
echo mb_strlen($s, 'UTF-8'), PHP_EOL;
echo mb_strlen('hello', 'UTF-8'), PHP_EOL;
--EXPECT--
6
6
6
5
