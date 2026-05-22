--TEST--
AOT: REQUEST_METHOD from refreshed $_SERVER (issue #201)
--ENV--
REQUEST_METHOD=POST
SCRIPT_NAME=/index.php
REQUEST_URI=/index.php
--FILE--
<?php
echo $_SERVER['REQUEST_METHOD'], "\n";
--EXPECT--
POST
--EXPECT_EXIT--
0
