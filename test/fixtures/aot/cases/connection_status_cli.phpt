--TEST--
AOT connection_status() CLI returns CONNECTION_NORMAL (issue #6161)
--FILE--
<?php
echo connection_status(), "\n";
echo connection_status() === CONNECTION_NORMAL ? "match\n" : "bad\n";
--EXPECT--
0
match
