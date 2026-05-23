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
--EXPECT--
1
Post
ok
