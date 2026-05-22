--TEST--
Web: duplicate query keys last-wins; empty pair ignored (issue #645, #68)
--ENV--
QUERY_STRING=a=1&a=2&=
--FILE--
<?php
echo 'a=', $_GET['a'], "\n";
--EXPECT--
a=2
