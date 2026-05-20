--TEST--
Web: application/json POST populates $_POST
--ENV--
REQUEST_METHOD=POST
REQUEST_BODY={"name":"JsonUser"}
CONTENT_TYPE=application/json
--FILE--
<?php
echo $_POST['name'];
--EXPECT--
JsonUser
