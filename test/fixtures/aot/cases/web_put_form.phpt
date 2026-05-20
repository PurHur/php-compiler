--TEST--
AOT: PUT form body populates $_POST (issue #291)
--ENV--
REQUEST_METHOD=PUT
REQUEST_BODY=name=Ada&role=dev
CONTENT_TYPE=application/x-www-form-urlencoded
--FILE--
<?php
echo 'Hello ', $_POST['name'], "\n";
echo 'role=', $_POST['role'], "\n";
--EXPECT--
Hello Ada
role=dev
--EXPECT_EXIT--
0
