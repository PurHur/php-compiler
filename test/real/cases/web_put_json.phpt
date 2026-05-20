--TEST--
Web: PUT JSON body via php://input (REQUEST_BODY)
--ENV--
REQUEST_METHOD=PUT
REQUEST_BODY={"id":42,"name":"Ada"}
CONTENT_TYPE=application/json
--FILE--
<?php
echo file_get_contents('php://input');
--EXPECT--
{"id":42,"name":"Ada"}
