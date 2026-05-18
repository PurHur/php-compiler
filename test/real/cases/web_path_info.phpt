--TEST--
Web: PATH_INFO from REQUEST_URI (front-controller routing)
--ENV--
SCRIPT_NAME=/index.php
REQUEST_URI=/index.php/admin/dashboard
REQUEST_METHOD=GET
--FILE--
<?php
echo isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '';
--EXPECT--
/admin/dashboard
