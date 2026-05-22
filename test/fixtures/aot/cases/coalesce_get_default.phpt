--TEST--
AOT: $_GET['name'] ?? default when key is absent (issues #99, #273)
--ENV--
QUERY_STRING=
--FILE--
<?php
echo $_GET['name'] ?? 'Guest', "\n";
--EXPECT--
Guest
--EXPECT_EXIT--
0
