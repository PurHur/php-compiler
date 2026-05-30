--TEST--
stdlib substr() JIT on UTF-8 bytes and boxed REQUEST name
--ENV--
QUERY_STRING=name=PostDev
--FILE--
<?php
$euro = "\xE2\x82\xAC";
echo strlen(substr($euro, 0, 1)), "\n";
$name = $_GET['name'] ?? '';
echo substr($name, 0, 4), "\n";
echo ($name != substr($name, 0, 200) ? 'long' : 'ok'), "\n";
class Trunc {
    public const MAX = 200;
}
$long = str_repeat('x', 250);
echo ($long != substr($long, 0, Trunc::MAX) ? 'long' : 'ok'), "\n";
--EXPECT--
1
Post
ok
long
