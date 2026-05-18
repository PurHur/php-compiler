--TEST--
AOT: $_GET string key (populated at compile time from QUERY_STRING)
--ENV--
QUERY_STRING=name=Compiled
--FILE--
<?php
echo $_GET['name'], "\n";
--EXPECT--
Compiled
