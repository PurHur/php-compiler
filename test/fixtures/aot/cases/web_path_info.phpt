--TEST--
AOT: PATH_INFO from REQUEST_URI (front-controller routing)
--ENV--
SCRIPT_NAME=/index.php
REQUEST_URI=/index.php/admin/dashboard
REQUEST_METHOD=GET
--FILE--
<?php
echo $_SERVER['PATH_INFO'];
--EXPECT--
/admin/dashboard
--EXPECT_EXIT--
0
