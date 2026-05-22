--TEST--
AOT: application/json POST populates $_POST (issue #271)
--ENV--
REQUEST_METHOD=POST
REQUEST_BODY={"name":"JsonUser"}
CONTENT_TYPE=application/json
--FILE--
<?php
echo $_POST['name'];
--EXPECT--
JsonUser
--EXPECT_EXIT--
0
