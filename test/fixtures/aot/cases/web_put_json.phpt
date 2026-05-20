--TEST--
AOT: PUT JSON body via php://input (REQUEST_BODY env, issue #291)
--ENV--
REQUEST_METHOD=PUT
REQUEST_BODY={"ok":true}
CONTENT_TYPE=application/json
--FILE--
<?php
echo file_get_contents('php://input');
--EXPECT--
{"ok":true}
--EXPECT_EXIT--
0
