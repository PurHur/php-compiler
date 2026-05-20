--TEST--
Web: file_get_contents('php://input') returns raw POST body
--ENV--
REQUEST_METHOD=POST
REQUEST_BODY={"ok":true}
CONTENT_TYPE=application/json
--FILE--
<?php
echo file_get_contents('php://input');
--EXPECT--
{"ok":true}
