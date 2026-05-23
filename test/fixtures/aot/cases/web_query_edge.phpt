--TEST--
AOT: duplicate and empty query keys (last-wins, Zend parse_str)
--ENV--
QUERY_STRING=a=1&a=2&=
--FILE--
<?php
echo 'a=', $_GET['a'], "\n";
echo 'count=', count($_GET), "\n";
--EXPECT--
a=2
count=1
--EXPECT_EXIT--
0
