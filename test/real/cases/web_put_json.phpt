--TEST--
Web: PUT with JSON body via php://input (issue #291)
--ENV--
REQUEST_METHOD=PUT
REQUEST_BODY={"ok":true}
CONTENT_TYPE=application/json
--FILE--
<?php
echo file_get_contents('php://input');
--EXPECT--
{"ok":true}
