--TEST--
JIT: file_get_contents('php://input') reads REQUEST_BODY
--ENV--
REQUEST_BODY={"ok":true}
--FILE--
<?php
echo file_get_contents('php://input');
--EXPECT--
{"ok":true}
