--TEST--
AOT: nested QUERY_STRING does not break flat $_GET keys (C runtime refresh)
--ENV--
QUERY_STRING=user[name]=Ada&flat=ok
--FILE--
<?php
echo $_GET['flat'], "\n";
--EXPECT--
ok
--EXPECT_EXIT--
0
