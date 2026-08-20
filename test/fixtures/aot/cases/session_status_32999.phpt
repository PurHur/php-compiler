--TEST--
AOT: session_status() compiles and returns PHP_SESSION_NONE (#32999)
--FILE--
<?php
echo session_status(), "\n";
echo session_status() === PHP_SESSION_NONE ? "none\n" : "bad\n";
--EXPECT--
1
none
--EXPECT_EXIT--
0
